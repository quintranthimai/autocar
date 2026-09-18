<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$booking = App\Models\Booking::with('renter', 'vehicle.carModel')->find(7);
if ($booking && $booking->renter) {
    $booking->renter->notify(new App\Notifications\BookingApprovedNotification($booking));
    echo "OK";
} else {
    echo "FAILED";
}
