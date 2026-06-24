<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = \App\Models\User::orderBy('created_at', 'desc')->take(10)->get();
foreach ($users as $user) {
    echo "ID: {$user->id} | Name: {$user->first_name} {$user->last_name} | Email: {$user->email} | Status: {$user->status} | Source: {$user->registration_source} | Created At: {$user->created_at}\n";
}
