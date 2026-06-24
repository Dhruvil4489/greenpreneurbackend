<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "ID Column Type: " . Schema::getColumnType('personal_access_tokens', 'id') . "\n";
print_r(Schema::getColumnListing('personal_access_tokens'));
