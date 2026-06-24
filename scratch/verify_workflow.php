<?php

use App\Models\User;
use App\Models\EmailLog;
use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Admin\PendingRegistrationsController;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$email = 'verify-workflow-user@example.com';
$password = 'Secret123!';

echo "=== GREENPRENEUR WORKFLOW VERIFICATION ===\n\n";

// Clean up old run if exists
echo "Cleaning up user if exists...\n";
$oldUser = User::where('email', $email)->first();
if ($oldUser) {
    EmailLog::where('user_id', $oldUser->id)->delete();
    AdminAuditLog::where('target_id', $oldUser->id)->delete();
    $oldUser->forceDelete();
    echo "Cleanup complete.\n";
}

// 1. REGISTRATION
echo "\n--- 1. REGISTERING NEW USER via API logic ---\n";

$registerRequest = \App\Http\Requests\Auth\RegisterRequest::create('/api/v1/auth/register', 'POST', [
    'first_name' => 'John',
    'last_name' => 'Doe',
    'email' => $email,
    'phone' => '1234567890',
    'password' => $password,
    'password_confirmation' => $password,
]);
$registerRequest->setContainer($app);
$registerRequest->setRedirector($app->make(\Illuminate\Routing\Redirector::class));
$registerRequest->validateResolved();

// Resolve dependencies
$authController = app(AuthController::class);
$referralService = app(\App\Services\Referrals\ReferralService::class);
$fileUploadService = app(\App\Services\Media\FileUploadService::class);

$registerResponse = $authController->register(
    $registerRequest,
    $referralService,
    $fileUploadService
);

echo "Response status: " . $registerResponse->getStatusCode() . "\n";
echo "Response JSON:\n";
print_r(json_decode($registerResponse->getContent(), true));

$user = User::where('email', $email)->firstOrFail();
echo "\nDatabase Check:\n";
echo "User ID: " . $user->id . "\n";
echo "User Status: " . $user->status . "\n";
echo "Registration Source: " . $user->registration_source . "\n";

// 2. EMAIL AFTER REGISTRATION
echo "\n--- 2. VERIFYING REGISTRATION REVIEW EMAIL ---\n";
$receivedMailLog = EmailLog::where('user_id', $user->id)
    ->where('template_key', 'registration_request_received')
    ->first();
if ($receivedMailLog) {
    echo "Email Log ID: " . $receivedMailLog->id . "\n";
    echo "Template Key: " . $receivedMailLog->template_key . "\n";
    echo "Recipient: " . $receivedMailLog->to_email . "\n";
    echo "Status: " . $receivedMailLog->status . "\n";
    echo "Subject: " . $receivedMailLog->subject . "\n";
} else {
    echo "Registration review email NOT found!\n";
}

// 3. LOGIN ATTEMPT (INACTIVE)
echo "\n--- 3. LOGIN ATTEMPT WHILE INACTIVE ---\n";
$loginRequest = Request::create('/api/v1/auth/login', 'POST', [
    'email' => $email,
    'password' => $password,
]);
$loginResponse = $authController->login($loginRequest);
echo "Response status: " . $loginResponse->getStatusCode() . "\n";
echo "Response JSON:\n";
print_r(json_decode($loginResponse->getContent(), true));

// Setup admin for approval
$admin = AdminUser::first();
if (!$admin) {
    // create a dummy admin
    $admin = AdminUser::create([
        'id' => (string) \Illuminate\Support\Str::uuid(),
        'name' => 'System Tester',
        'email' => 'system.tester@example.com',
    ]);
}
Auth::guard('admin')->setUser($admin);

// 4. APPROVAL FLOW
echo "\n--- 4. APPROVING USER REGISTRATION via Admin Controller logic ---\n";
$approveRequest = Request::create("/admin/pending-requests/pending-registrations/{$user->id}/approve", 'POST');
$pendingController = app(PendingRegistrationsController::class);
$approveResponse = $pendingController->approve($user, $approveRequest);

$user->refresh();
echo "Updated Status after Approve: " . $user->status . "\n";

// Verify audit log
$auditLog = AdminAuditLog::where('target_id', $user->id)
    ->where('action', 'approve_registration')
    ->first();
if ($auditLog) {
    echo "Audit Log ID: " . $auditLog->id . "\n";
    echo "Action: " . $auditLog->action . "\n";
    echo "Details Old: " . json_encode($auditLog->details) . "\n";
} else {
    echo "Audit Log for approval NOT found!\n";
}

// Verify approval email
$approvedMailLog = EmailLog::where('user_id', $user->id)
    ->where('template_key', 'registration_approved')
    ->first();
if ($approvedMailLog) {
    echo "Email Log ID: " . $approvedMailLog->id . "\n";
    echo "Template: " . $approvedMailLog->template_key . "\n";
    echo "Recipient: " . $approvedMailLog->to_email . "\n";
    echo "Status: " . $approvedMailLog->status . "\n";
    echo "Subject: " . $approvedMailLog->subject . "\n";
} else {
    echo "Approval email NOT found!\n";
}

// 5. LOGIN ATTEMPT (APPROVED)
echo "\n--- 5. LOGIN ATTEMPT AFTER APPROVAL ---\n";
$loginRequestApproved = Request::create('/api/v1/auth/login', 'POST', [
    'email' => $email,
    'password' => $password,
]);
$loginResponseApproved = $authController->login($loginRequestApproved);
echo "Response status: " . $loginResponseApproved->getStatusCode() . "\n";
echo "Response JSON:\n";
$loginData = json_decode($loginResponseApproved->getContent(), true);
// Mask token for display
if (isset($loginData['data']['token'])) {
    $loginData['data']['token'] = 'MOCKED_TOKEN_EXISTS';
}
print_r($loginData);

// 6. REJECTION FLOW
echo "\n--- 6. REJECTING USER REGISTRATION via Admin Controller logic ---\n";
// Re-initialize user for testing transition from active back to rejected
$user->status = 'inactive';
$user->save();

$rejectRequest = Request::create("/admin/pending-requests/pending-registrations/{$user->id}/reject", 'POST');
$rejectResponse = $pendingController->reject($user, $rejectRequest);

$user->refresh();
echo "Updated Status after Reject: " . $user->status . "\n";

// Verify audit log for rejection
$rejectAuditLog = AdminAuditLog::where('target_id', $user->id)
    ->where('action', 'reject_registration')
    ->first();
if ($rejectAuditLog) {
    echo "Audit Log ID: " . $rejectAuditLog->id . "\n";
    echo "Action: " . $rejectAuditLog->action . "\n";
    echo "Details Old: " . json_encode($rejectAuditLog->details) . "\n";
} else {
    echo "Audit Log for rejection NOT found!\n";
}

// Verify rejection email
$rejectedMailLog = EmailLog::where('user_id', $user->id)
    ->where('template_key', 'registration_rejected')
    ->first();
if ($rejectedMailLog) {
    echo "Email Log ID: " . $rejectedMailLog->id . "\n";
    echo "Template: " . $rejectedMailLog->template_key . "\n";
    echo "Recipient: " . $rejectedMailLog->to_email . "\n";
    echo "Status: " . $rejectedMailLog->status . "\n";
    echo "Subject: " . $rejectedMailLog->subject . "\n";
} else {
    echo "Rejection email NOT found!\n";
}

// 7. LOGIN ATTEMPT (REJECTED)
echo "\n--- 7. LOGIN ATTEMPT AFTER REJECTION ---\n";
$loginRequestRejected = Request::create('/api/v1/auth/login', 'POST', [
    'email' => $email,
    'password' => $password,
]);
$loginResponseRejected = $authController->login($loginRequestRejected);
echo "Response status: " . $loginResponseRejected->getStatusCode() . "\n";
echo "Response JSON:\n";
print_r(json_decode($loginResponseRejected->getContent(), true));

// Clean up
echo "\nCleaning up verify-workflow-user...\n";
EmailLog::where('user_id', $user->id)->delete();
AdminAuditLog::where('target_id', $user->id)->delete();
$user->forceDelete();
echo "Cleanup complete.\n";
echo "Workflow verification finished successfully!\n";
