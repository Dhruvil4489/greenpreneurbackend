<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Http\Controllers\Controller;
use App\Models\AdminLoginOtp;
use App\Models\AdminUser;
use App\Support\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DedAuthController extends Controller
{
    public function requestOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $admin = $this->dedAdminByEmail($email);

        if (! $admin || ! AdminAccess::isDed($admin)) {
            return $this->error('Only DED admin users can request an OTP.', 403);
        }

        $recentOtp = AdminLoginOtp::query()
            ->where('email', $email)
            ->orderByDesc('created_at')
            ->first();

        if ($recentOtp && $recentOtp->last_sent_at && $recentOtp->last_sent_at->diffInSeconds(now()->utc()) < 30) {
            return $this->error('Please wait before requesting another OTP.', 429);
        }

        $otp = (string) random_int(1000, 9999);
        $now = now()->utc();

        $otpRecord = AdminLoginOtp::query()->where('email', $email)->first();
        if (! $otpRecord) {
            $otpRecord = new AdminLoginOtp();
            $otpRecord->id = (string) Str::uuid();
            $otpRecord->email = $email;
        }

        $otpRecord->otp_hash = Hash::make($otp);
        $otpRecord->expires_at = $now->copy()->addMinutes(5);
        $otpRecord->last_sent_at = $now;
        $otpRecord->attempts = 0;
        $otpRecord->used_at = null;
        $otpRecord->save();

        Mail::raw(
            "Your DED API login OTP is {$otp}. It expires in 5 minutes.",
            static function ($message) use ($email): void {
                $message->to($email)->subject('Your DED API Login OTP');
            }
        );

        return $this->success([], 'OTP sent successfully.');
    }

    public function verifyOtp(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:4'],
        ]);

        $email = mb_strtolower(trim($validated['email']));
        $admin = $this->dedAdminByEmail($email);

        if (! $admin || ! AdminAccess::isDed($admin)) {
            return $this->error('Only DED admin users can verify OTP.', 403);
        }

        $location = AdminAccess::assignedDedLocation($admin);
        if (empty($location['district_name'])) {
            return $this->error('DED district assignment is missing.', 403);
        }

        $result = DB::transaction(function () use ($email, $validated): array {
            $now = now()->utc();

            $otpRecord = AdminLoginOtp::query()
                ->where('email', $email)
                ->whereNull('used_at')
                ->where('expires_at', '>=', $now)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (! $otpRecord) {
                return ['status' => 410, 'message' => 'OTP expired or invalid.'];
            }

            if ($otpRecord->attempts >= 5) {
                return ['status' => 423, 'message' => 'Too many attempts.'];
            }

            if (! Hash::check(trim($validated['otp']), $otpRecord->otp_hash)) {
                $otpRecord->attempts += 1;
                $otpRecord->updated_at = $now;
                $otpRecord->save();

                return ['status' => 422, 'message' => 'Invalid OTP.'];
            }

            $otpRecord->used_at = $now;
            $otpRecord->updated_at = $now;
            $otpRecord->attempts += 1;
            $otpRecord->save();

            return ['status' => 200, 'message' => 'OTP verified.'];
        });

        if ($result['status'] !== 200) {
            return $this->error($result['message'], $result['status']);
        }

        $token = $admin->createToken('ded_api')->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'ded',
                'district' => $location['district_name'] ?? null,
                'state' => $location['state_name'] ?? null,
            ],
        ], 'DED login successful.');
    }

    private function dedAdminByEmail(string $email): ?AdminUser
    {
        return AdminUser::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();
    }

    private function success($data = [], string $message = 'OK', int $status = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => (object) [],
        ], $status);
    }

    private function error(string $message, int $status = 400, array $errors = [])
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }
}
