<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminLoginOtp;
use App\Models\AdminUser;
use App\Models\IndustryDirectorAssignment;
use App\Models\CircleMember;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function showLogin(Request $request): RedirectResponse|View
    {
        if (Auth::guard('admin')->check()) {
            $adminUser = Auth::guard('admin')->user();

            return redirect()->route(AdminAccess::isDed($adminUser) ? 'admin.ded.dashboard' : 'admin.dashboard');
            if ($this->shouldRedirectToIndustryDirectorDashboard($adminUser)) {
                return redirect()->route('admin.industry-director.dashboard');
            }

            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    public function requestOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim($validated['email']));
        $adminUser = $this->eligibleAdmin($email);

        if (! $adminUser) {
            return back()
                ->withInput(['email' => $email])
                ->withErrors(['email' => 'You are not admin']);
        }

        $recentOtp = AdminLoginOtp::query()
            ->where('email', $email)
            ->orderByDesc('created_at')
            ->first();

        if ($recentOtp && $recentOtp->last_sent_at && $recentOtp->last_sent_at->diffInSeconds(now()->utc()) < 30) {
            return back()
                ->withInput(['email' => $email])
                ->withErrors(['email' => 'Please wait before requesting another OTP.']);
        }

        $otp = (string) random_int(1000, 9999);
        $now = now()->utc();
        $expiresAt = $now->copy()->addMinutes(5);

        $otpRecord = AdminLoginOtp::query()->where('email', $email)->first();

        if (! $otpRecord) {
            $otpRecord = new AdminLoginOtp();
            $otpRecord->id = (string) Str::uuid();
            $otpRecord->email = $email;
        }

        $otpRecord->otp_hash = Hash::make($otp);
        $otpRecord->expires_at = $expiresAt;
        $otpRecord->last_sent_at = $now;
        $otpRecord->attempts = 0;
        $otpRecord->used_at = null;
        $otpRecord->save();

        Mail::raw(
            "Your admin login OTP is {$otp}. It expires in 5 minutes.",
            static function ($message) use ($email): void {
                $message->to($email)->subject('Your Admin Login OTP');
            }
        );

        $request->session()->forget('errors');
        $request->session()->put('admin_login_email', $email);

        return redirect()
            ->route('admin.login')
            ->withInput(['email' => $email])
            ->with('otp_sent', true)
            ->with('status', 'OTP sent');
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:4'],
        ]);

        $email = strtolower(trim($validated['email']));
        $otp = trim($validated['otp']);

        $adminUser = $this->eligibleAdmin($email);

        if (! $adminUser) {
            return back()->withErrors(['email' => 'You are not admin']);
        }

        $result = DB::transaction(function () use ($email, $otp): array {
            $now = now()->utc();

            $otpRecord = AdminLoginOtp::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->where('expires_at', '>=', $now)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (app()->environment('local')) {
                Log::info('ADMIN OTP TIME CHECK', [
                    'app_now' => now()->toIso8601String(),
                    'utc_now' => $now->toIso8601String(),
                    'expires_at' => optional($otpRecord)->expires_at?->toIso8601String(),
                ]);
            }

            if (! $otpRecord) {
                return ['status' => 410, 'message' => 'OTP expired or invalid'];
            }

            if ($otpRecord->attempts >= 5) {
                return ['status' => 423, 'message' => 'Too many attempts'];
            }

            if (! Hash::check($otp, $otpRecord->otp_hash)) {
                $otpRecord->attempts += 1;
                $otpRecord->updated_at = $now;
                $otpRecord->save();

                return ['status' => 422, 'message' => 'Invalid OTP'];
            }

            $otpRecord->used_at = $now;
            $otpRecord->updated_at = $now;
            $otpRecord->attempts += 1;
            $otpRecord->save();

            return ['status' => 200, 'message' => 'OTP verified'];
        });

        if ($result['status'] !== 200) {
            return back()
                ->withInput(['email' => $email])
                ->withErrors(['otp' => $result['message']]);
        }

        Auth::guard('admin')->login($adminUser);
        $request->session()->put('admin_user_id', $adminUser->id);
        $request->session()->put('admin_login_email', $adminUser->email);
        $request->session()->regenerate();

        return redirect()->route(AdminAccess::isDed($adminUser) ? 'admin.ded.dashboard' : 'admin.dashboard');
        if ($this->shouldRedirectToIndustryDirectorDashboard($adminUser)) {
            return redirect()->route('admin.industry-director.dashboard');
        }

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->forget(['admin_user_id', 'admin_login_email']);
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function shouldRedirectToIndustryDirectorDashboard(?AdminUser $adminUser): bool
    {
        if (! $adminUser) {
            return false;
        }

        $adminUser->loadMissing('roles:id,key');

        if (! $adminUser->roles->pluck('key')->contains('industry_director')) {
            return false;
        }

        return IndustryDirectorAssignment::query()
            ->where('admin_user_id', $adminUser->id)
            ->where('is_active', true)
            ->exists();
    }

    private function eligibleAdmin(string $email): ?AdminUser
    {
        $adminUser = AdminUser::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($adminUser) {
            return $adminUser;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user) {
            return null;
        }

        $eligibleRoles = ['chair', 'vice_chair', 'secretary', 'founder', 'director'];

        $isEligibleLeader = CircleMember::query()
            ->where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereIn(DB::raw('circle_members.role::text'), $eligibleRoles)
            ->exists();

        if (! $isEligibleLeader) {
            return null;
        }

        return DB::transaction(function () use ($user): AdminUser {
            $adminUser = AdminUser::query()
                ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
                ->first();

            if (! $adminUser) {
                $adminUser = AdminUser::create([
                    'id' => (string) Str::uuid(),
                    'name' => $this->resolveAdminName($user),
                    'email' => strtolower($user->email),
                ]);
            }

            $circleLeaderRoleId = Role::mustIdByKey('circle_leader');

            $hasCircleLeaderRole = DB::table('admin_user_roles')
                ->where('user_id', $adminUser->id)
                ->where('role_id', $circleLeaderRoleId)
                ->exists();

            if (! $hasCircleLeaderRole) {
                DB::table('admin_user_roles')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $adminUser->id,
                    'role_id' => $circleLeaderRoleId,
                    'created_at' => now(),
                ]);

                Cache::forget('admin-access:roles:' . $adminUser->id);
            }

            return $adminUser;
        });
    }

    private function resolveAdminName(User $user): string
    {
        if (! empty($user->display_name)) {
            return $user->display_name;
        }

        $fullName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        return $fullName !== '' ? $fullName : $user->email;
    }
}
