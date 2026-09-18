<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$booking = App\Models\Booking::with('vehicle.owner')->find(7);
if ($booking && $booking->vehicle && $booking->vehicle->owner) {
    $booking->vehicle->owner->notify(new App\Notifications\NewBookingRequestNotification($booking));
    echo "OK";
} else {
    echo "FAILED";
}
