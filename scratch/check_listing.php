<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Http\Controllers\Admin\PendingRegistrationsController;
use Illuminate\Http\Request;

$admin = \App\Models\AdminUser::first();
if ($admin) {
    Auth::guard('admin')->setUser($admin);
}

$request = Request::create('/admin/pending-requests/pending-registrations', 'GET', ['status' => 'inactive']);
$controller = app(PendingRegistrationsController::class);
$view = $controller->index($request);

$registrations = $view->getData()['registrations'];
echo "Total Inactive Registrations: " . $registrations->total() . "\n";
echo "Listing users:\n";
foreach ($registrations as $reg) {
    echo "- ID: {$reg->id} | Email: {$reg->email} | Status: {$reg->status} | Source: {$reg->registration_source}\n";
}
