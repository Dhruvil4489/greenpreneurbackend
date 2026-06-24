<?php
$email = 'new-api-user-' . time() . '@example.com';
$phone = '9' . substr(time(), 0, 9);
$payload = [
    'first_name' => 'MobileApp',
    'last_name' => 'Simulator',
    'email' => $email,
    'phone' => $phone,
    'password' => 'Secure123!',
    'password_confirmation' => 'Secure123!',
];

$url = 'http://127.0.0.1:8000/api/v1/auth/register';

echo "Sending registration request to: {$url}\n";
echo "Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: {$httpCode}\n";
echo "Response Body:\n{$response}\n\n";

// Now, connect to the database to inspect the state of the user we just created.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::where('email', $email)->first();
if ($user) {
    echo "=== DATABASE RECORD FOUND ===\n";
    echo "User ID: {$user->id}\n";
    echo "Status: {$user->status}\n";
    echo "Registration Source: {$user->registration_source}\n";
    echo "Created At: {$user->created_at}\n\n";

    // Verify email log
    $emailLog = \App\Models\EmailLog::where('user_id', $user->id)->first();
    if ($emailLog) {
        echo "=== EMAIL LOG FOUND ===\n";
        echo "Log ID: {$emailLog->id}\n";
        echo "Template Key: {$emailLog->template_key}\n";
        echo "Subject: {$emailLog->subject}\n";
        echo "Status: {$emailLog->status}\n\n";
    } else {
        echo "=== NO EMAIL LOG FOUND ===\n\n";
    }
} else {
    echo "=== USER NOT CREATED IN DATABASE ===\n\n";
}
