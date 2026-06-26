<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

try {
    $sql = file_get_contents(__DIR__ . '/../database/manual_sql/greenpreneur_registration_fields.sql');
    
    // Split queries by semicolon to execute them individually
    // A simple regex split on semicolon that is not in a string is not necessary since ALTER TABLEs are simple.
    // We can also just run DB::unprepared.
    DB::unprepared($sql);
    echo "SQL manual update applied successfully on local database.\n";
    
    // Verify
    $verify = DB::select("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_name = 'users' 
          AND column_name IN ('website', 'sustainability_contribution', 'sustainability_areas', 'greenpreneur_goals', 'community_directory_listing')
    ");
    print_r($verify);
} catch (\Exception $e) {
    echo "Error applying SQL: " . $e->getMessage() . "\n";
}
