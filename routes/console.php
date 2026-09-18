<?php

use App\Jobs\AutoCancelExpiredBookingsJob;
use App\Models\Booking;
use App\Notifications\BookingPaymentReminderNotification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bookings:auto-cancel-expired', function () {
    $cancelledCount = app(AutoCancelExpiredBookingsJob::class)->handle();

    $this->info("Auto-cancel completed. Cancelled {$cancelledCount} booking(s).");
})->purpose('Auto-cancel overdue pending_approval and pending_payment bookings');

Artisan::command('bookings:send-payment-reminders', function () {
    $now = now();
    $reminderThreshold = $now->copy()->subHour();
    $paymentCutoff = $now->copy()->subHours(2);
    $sentCount = 0;

    $candidateIds = Booking::query()
        ->where('status', 'pending_payment')
        ->whereNull('payment_reminder_sent_at')
        ->where('updated_at', '<=', $reminderThreshold)
        ->where('updated_at', '>', $paymentCutoff)
        ->pluck('id');

    foreach ($candidateIds as $bookingId) {
        DB::transaction(function () use ($bookingId, $reminderThreshold, $paymentCutoff, &$sentCount) {
            $booking = Booking::with(['renter', 'vehicle.carModel'])
                ->lockForUpdate()
                ->find($bookingId);

            if (!$booking || !$booking->renter) {
                return;
            }

            $isEligible = $booking->status === 'pending_payment'
                && $booking->payment_reminder_sent_at === null
                && $booking->updated_at <= $reminderThreshold
                && $booking->updated_at > $paymentCutoff;

            if (!$isEligible) {
                return;
            }

            $booking->renter->notify(new BookingPaymentReminderNotification($booking));
            $booking->payment_reminder_sent_at = now();
            $booking->save();

            $sentCount++;
        });
    }

    $this->info("Payment reminder completed. Sent {$sentCount} reminder(s).");
})->purpose('Send one-time payment reminder emails for pending_payment bookings');

Schedule::command('bookings:auto-cancel-expired')->everyMinute();
Schedule::command('bookings:send-payment-reminders')->everyFiveMinutes();
Schedule::job(new \App\Jobs\ReleasePendingTransactionsJob)->dailyAt('00:00');
