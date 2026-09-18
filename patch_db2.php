<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$affected = DB::table('notifications')
    ->where('type', 'App\Notifications\NewBookingRequestNotification')
    ->update(['data' => DB::raw("REPLACE(data, '\\\\/partner\\\\/bookings', '\\\\/partner\\\\/requests')")]);

if ($affected === 0) {
    // If there were no slashes escaped
    $affected = DB::table('notifications')
        ->where('type', 'App\Notifications\NewBookingRequestNotification')
        ->update(['data' => DB::raw("REPLACE(data, '/partner/bookings', '/partner/requests')")]);
}

echo "Updated $affected records.";
