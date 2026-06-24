<?php
// Run: php scratch/check_enum.php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = \DB::select(
    "SELECT enumlabel FROM pg_enum
     JOIN pg_type ON pg_enum.enumtypid = pg_type.oid
     WHERE pg_type.typname = 'membership_status_enum'
     ORDER BY enumsortorder"
);

if (empty($rows)) {
    echo "No enum type named 'membership_status_enum' found.\n";
    echo "Checking if membership_status is a plain text/varchar column:\n";
    $colType = \DB::select(
        "SELECT data_type, udt_name FROM information_schema.columns
         WHERE table_name = 'users' AND column_name = 'membership_status'"
    );
    foreach ($colType as $c) {
        echo "  data_type: {$c->data_type}, udt_name: {$c->udt_name}\n";
    }

    echo "\nDistinct values currently in DB:\n";
    $vals = \DB::select("SELECT DISTINCT membership_status, COUNT(*) as cnt FROM users GROUP BY membership_status ORDER BY cnt DESC");
    foreach ($vals as $v) {
        echo "  '{$v->membership_status}' => {$v->cnt} users\n";
    }
} else {
    echo "Enum values in membership_status_enum:\n";
    foreach ($rows as $r) {
        echo "  '{$r->enumlabel}'\n";
    }
}
