<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Str;

try {
    echo "Starting manual database validation flow...\n";

    // 1. Create a temporary user
    $user = new User();
    $user->id = (string) Str::uuid();
    $user->first_name = "Eco";
    $user->last_name = "Pioneer";
    $user->email = "eco-pioneer-" . time() . "@example.com";
    $user->password_hash = password_hash("secret123", PASSWORD_DEFAULT);
    
    // Set new fields
    $user->website = "https://ecopioneer.org";
    $user->sustainability_contribution = "We plant 10 trees for every transaction.";
    $user->sustainability_areas = ['Renewable Energy', 'Waste Management', 'Circular Economy'];
    $user->greenpreneur_goals = ['Business Growth', 'Partnerships', 'Sustainability Learning'];
    $user->interests = ['Mentoring', 'Panel Discussions'];
    $user->community_directory_listing = 'Yes';
    
    $user->save();
    
    echo "Saved user: {$user->id}\n";

    // 2. Fetch from database to verify types and casts
    $fetched = User::findOrFail($user->id);
    
    echo "Verifying website... ";
    if ($fetched->website === "https://ecopioneer.org") {
        echo "OK\n";
    } else {
        echo "FAIL (Got: {$fetched->website})\n";
    }

    echo "Verifying contribution... ";
    if ($fetched->sustainability_contribution === "We plant 10 trees for every transaction.") {
        echo "OK\n";
    } else {
        echo "FAIL\n";
    }

    echo "Verifying sustainability areas type... ";
    if (is_array($fetched->sustainability_areas)) {
        echo "OK (array)\n";
        print_r($fetched->sustainability_areas);
    } else {
        echo "FAIL (Got type: " . gettype($fetched->sustainability_areas) . ")\n";
    }

    echo "Verifying greenpreneur goals type... ";
    if (is_array($fetched->greenpreneur_goals)) {
        echo "OK (array)\n";
        print_r($fetched->greenpreneur_goals);
    } else {
        echo "FAIL\n";
    }

    echo "Verifying interests type... ";
    if (is_array($fetched->interests)) {
        echo "OK (array)\n";
        print_r($fetched->interests);
    } else {
        echo "FAIL\n";
    }

    echo "Verifying listing... ";
    if ($fetched->community_directory_listing === 'Yes') {
        echo "OK\n";
    } else {
        echo "FAIL\n";
    }

    // 3. Clean up
    $fetched->delete();
    echo "Cleaned up test user.\n";
    echo "Manual verification complete successfully!\n";

} catch (\Exception $e) {
    echo "Error during validation: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
