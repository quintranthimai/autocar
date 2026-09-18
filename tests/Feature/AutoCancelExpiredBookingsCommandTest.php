<?php

namespace Tests\Feature;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutoCancelExpiredBookingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_cancels_pending_payment_booking_that_is_over_two_hours_old(): void
    {
        $booking = $this->createBooking([
            'status' => 'pending_payment',
            'updated_at' => Carbon::now()->subMinutes(121),
        ]);

        Artisan::call('bookings:auto-cancel-expired');

        $booking->refresh();

        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('system', $booking->cancel_by);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertStringContainsString('2 giờ', $booking->cancel_reason);
    }

    public function test_it_does_not_cancel_pending_payment_booking_within_two_hours(): void
    {
        $booking = $this->createBooking([
            'status' => 'pending_payment',
            'updated_at' => Carbon::now()->subMinutes(119),
        ]);

        Artisan::call('bookings:auto-cancel-expired');

        $booking->refresh();

        $this->assertSame('pending_payment', $booking->status);
        $this->assertNull($booking->cancelled_at);
    }

    public function test_it_cancels_pending_approval_booking_when_trip_start_time_has_passed(): void
    {
        $booking = $this->createBooking([
            'status' => 'pending_approval',
            'start_datetime' => Carbon::now()->subMinutes(10),
            'end_datetime' => Carbon::now()->addHours(4),
        ]);

        Artisan::call('bookings:auto-cancel-expired');

        $booking->refresh();

        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('system', $booking->cancel_by);
        $this->assertStringContainsString('Chủ xe', $booking->cancel_reason);
    }

    public function test_it_cancels_pending_approval_booking_that_is_over_two_hours_old(): void
    {
        $booking = $this->createBooking([
            'status' => 'pending_approval',
            'start_datetime' => Carbon::now()->addHours(8),
            'end_datetime' => Carbon::now()->addHours(20),
            'created_at' => Carbon::now()->subMinutes(121),
        ]);

        Artisan::call('bookings:auto-cancel-expired');

        $booking->refresh();

        $this->assertSame('cancelled', $booking->status);
        $this->assertSame('system', $booking->cancel_by);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertStringContainsString('Chủ xe', $booking->cancel_reason);
    }

    private function createBooking(array $override = []): Booking
    {
        $now = Carbon::now();

        $ownerId = DB::table('users')->insertGetId([
            'name' => 'Owner ' . uniqid(),
            'email' => 'owner_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'phone' => '09' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $renterId = DB::table('users')->insertGetId([
            'name' => 'Renter ' . uniqid(),
            'email' => 'renter_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'phone' => '08' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'category_' . uniqid(),
            'display_name' => 'Category Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fuelId = DB::table('fuels')->insertGetId([
            'name' => 'fuel_' . uniqid(),
            'display_name' => 'Fuel Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $transmissionId = DB::table('transmissions')->insertGetId([
            'name' => 'trans_' . uniqid(),
            'display_name' => 'Transmission Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $carModelId = DB::table('car_models')->insertGetId([
            'category_id' => $categoryId,
            'fuel_id' => $fuelId,
            'transmission_id' => $transmissionId,
            'brand_name' => 'Brand Test',
            'model_name' => 'Model ' . uniqid(),
            'seat_count' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $vehicleId = DB::table('vehicles')->insertGetId([
            'owner_id' => $ownerId,
            'car_model_id' => $carModelId,
            'license_plate' => 'LP' . uniqid(),
            'vin_number' => 'VIN' . uniqid(),
            'engine_number' => 'EN' . uniqid(),
            'year' => 2024,
            'base_price' => 900000,
            'parking_address' => 'Ha Noi',
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payload = array_merge([
            'renter_id' => $renterId,
            'vehicle_id' => $vehicleId,
            'pickup_location' => 'Ha Noi',
            'dropoff_location' => 'Ha Noi',
            'start_datetime' => Carbon::now()->addHours(1),
            'end_datetime' => Carbon::now()->addHours(10),
            'total_amount' => 1200000,
            'status' => 'pending_payment',
            'payment_option' => 'deposit',
            'deposit_amount' => 360000,
            'created_at' => $now,
            'updated_at' => $now,
        ], $override);

        $bookingId = DB::table('bookings')->insertGetId($payload);

        return Booking::findOrFail($bookingId);
    }
}
