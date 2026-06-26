<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Str;

$email = 'gp_test_user@example.com';
$phone = '9876543210';
User::where('email', $email)->orWhere('phone', $phone)->forceDelete();

$user = new User();
$user->id = (string) Str::uuid();
$user->first_name = 'Greenpreneur';
$user->last_name = 'TestUser';
$user->display_name = 'Greenpreneur TestUser';
$user->email = $email;
$user->phone = $phone;
$user->password_hash = password_hash('password123', PASSWORD_BCRYPT);
$user->status = 'inactive';
$user->registration_source = 'App';
$user->website = 'https://greentest.com';
$user->sustainability_contribution = "We use biodegradable packaging and reduce CO2 emissions by 25% across our factories. Additionally, we run on solar power.";
$user->sustainability_areas = ['Renewable Energy', 'Waste Reduction', 'Circular Economy'];
$user->greenpreneur_goals = ['Eco-friendly branding', 'Sustainable supply chain', 'Networking'];
$user->interests = ['Speaking Opportunities', 'Panel Discussions', 'Green Investment'];
$user->community_directory_listing = 'Yes';
$user->save();

echo "User created successfully with ID: " . $user->id . "\n";
