<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAuditService;
use App\Services\EmailLogs\EmailLogService;
use App\Mail\RegistrationApprovedMail;
use App\Mail\RegistrationRejectedMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PendingRegistrationsController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $auditService,
        private readonly EmailLogService $emailLogService
    ) {
    }

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'inactive');
        $search = trim((string) $request->query('search', ''));
        $date = trim((string) $request->query('date', ''));

        $query = User::query()->where('registration_source', 'App');

        // Allow filtering by status, but restrict to inactive / rejected / active.
        // Default to inactive if invalid status is supplied.
        if (in_array($status, ['inactive', 'rejected', 'active'], true)) {
            $query->where('status', $status);
        } else {
            $query->where('status', 'inactive');
        }

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('display_name', 'ILIKE', $like)
                    ->orWhere('first_name', 'ILIKE', $like)
                    ->orWhere('last_name', 'ILIKE', $like)
                    ->orWhere('email', 'ILIKE', $like)
                    ->orWhere('phone', 'ILIKE', $like)
                    ->orWhere('city_of_residence', 'ILIKE', $like)
                    ->orWhere('city', 'ILIKE', $like);
            });
        }

        if ($date !== '') {
            $query->whereDate('created_at', $date);
        }

        $registrations = $query->orderByDesc('created_at')
            ->paginate(25)
            ->appends($request->query());

        return view('admin.pending-registrations.index', [
            'registrations' => $registrations,
            'filters' => [
                'status' => $status,
                'search' => $search,
                'date' => $date,
            ],
            'statuses' => ['inactive', 'rejected', 'active'],
        ]);
    }

    public function approve(User $user, Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin) {
            abort(403);
        }

        try {
            $message = DB::transaction(function () use ($user, $admin, $request) {
                // Lock row for update
                $user = User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();

                if ($user->status === 'active') {
                    return 'Account is already active.';
                }

                $oldStatus = $user->status;
                $oldMembershipStatus = $user->membership_status;

                $user->status = 'active';
                $user->membership_status = User::STATUS_GREEN_PEER;
                $user->save();

                // Log audit trail (captures both status and membership_status changes)
                $this->auditService->log(
                    $admin,
                    'approve_registration',
                    'users',
                    $user->id,
                    ['status' => $oldStatus, 'membership_status' => $oldMembershipStatus],
                    ['status' => 'active', 'membership_status' => User::STATUS_GREEN_PEER],
                    $request
                );

                // Send professional approval email
                $mailable = new RegistrationApprovedMail($user);
                Mail::to($user->email)->send($mailable);

                // Log email
                $this->emailLogService->logMailableSent($mailable, [
                    'user_id' => (string) $user->id,
                    'to_email' => (string) $user->email,
                    'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                    'template_key' => 'registration_approved',
                    'source_module' => 'Auth',
                    'related_type' => User::class,
                    'related_id' => (string) $user->id,
                    'payload' => [
                        'purpose' => 'registration_approval',
                    ],
                ]);

                return 'Account registration approved successfully.';
            });

            return redirect()->back()->with('success', $message);

        } catch (\Throwable $exception) {
            Log::error('Registration approval failed', [
                'user_id' => $user->id,
                'admin_id' => $admin->id,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Failed to approve registration: ' . $exception->getMessage());
        }
    }

    public function reject(User $user, Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();
        if (! $admin) {
            abort(403);
        }

        try {
            $message = DB::transaction(function () use ($user, $admin, $request) {
                // Lock row for update
                $user = User::query()->where('id', $user->id)->lockForUpdate()->firstOrFail();

                if ($user->status === 'rejected') {
                    return 'Account is already rejected.';
                }

                $oldStatus = $user->status;
                $user->status = 'rejected';
                $user->save();

                // Log audit trail
                $this->auditService->log(
                    $admin,
                    'reject_registration',
                    'users',
                    $user->id,
                    ['status' => $oldStatus],
                    ['status' => 'rejected'],
                    $request
                );

                // Send professional rejection email
                $mailable = new RegistrationRejectedMail($user);
                Mail::to($user->email)->send($mailable);

                // Log email
                $this->emailLogService->logMailableSent($mailable, [
                    'user_id' => (string) $user->id,
                    'to_email' => (string) $user->email,
                    'to_name' => (string) ($user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))),
                    'template_key' => 'registration_rejected',
                    'source_module' => 'Auth',
                    'related_type' => User::class,
                    'related_id' => (string) $user->id,
                    'payload' => [
                        'purpose' => 'registration_rejection',
                    ],
                ]);

                return 'Account registration rejected successfully.';
            });

            return redirect()->back()->with('success', $message);

        } catch (\Throwable $exception) {
            Log::error('Registration rejection failed', [
                'user_id' => $user->id,
                'admin_id' => $admin->id,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Failed to reject registration: ' . $exception->getMessage());
        }
    }
}
