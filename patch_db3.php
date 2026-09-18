<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$notifications = App\Models\User::find(2)->notifications()->where('type', 'App\Notifications\NewBookingRequestNotification')->get();

foreach ($notifications as $notification) {
    $data = $notification->data;
    if (isset($data['to']) && $data['to'] === '/partner/bookings') {
        $data['to'] = '/partner/requests';
        $notification->data = $data;
        $notification->save();
        echo "Updated notification ID: " . $notification->id . "\n";
    }
}
echo "Done.";
