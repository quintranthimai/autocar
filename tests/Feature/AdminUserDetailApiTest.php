<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CarModel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserDetailApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_admin_can_view_renter_detail_with_booking_filter_and_pagination(): void
    {
        $masterAdmin = $this->createUserWithRole('master_admin');
        $renter = $this->createUserWithRole('renter');
        $owner = $this->createUserWithRole('owner');

        $carModel = $this->createCarModel();
        $vehicle = $this->createVehicle($owner->id, $carModel->id, 'available');

        Booking::create([
            'renter_id' => $renter->id,
            'vehicle_id' => $vehicle->id,
            'pickup_location' => 'A',
            'dropoff_location' => 'B',
            'start_datetime' => now()->addDay(),
            'end_datetime' => now()->addDays(2),
            'total_amount' => 1000000,
            'status' => 'completed',
        ]);

        Booking::create([
            'renter_id' => $renter->id,
            'vehicle_id' => $vehicle->id,
            'pickup_location' => 'A',
            'dropoff_location' => 'B',
            'start_datetime' => now()->addDays(3),
            'end_datetime' => now()->addDays(4),
            'total_amount' => 1500000,
            'status' => 'cancelled',
        ]);

        Sanctum::actingAs($masterAdmin);

        $response = $this->getJson('/api/v1/admin/users/' . $renter->id . '?bookings_status=completed&bookings_per_page=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bookings.total', 1)
            ->assertJsonPath('data.bookings.per_page', 1)
            ->assertJsonPath('data.bookings.data.0.status', 'completed')
            ->assertJsonPath('data.vehicles', null);
    }

    public function test_master_admin_can_view_owner_detail_with_vehicle_filter_and_pagination(): void
    {
        $masterAdmin = $this->createUserWithRole('master_admin');
        $owner = $this->createUserWithRole('owner');

        $carModel = $this->createCarModel();
        $this->createVehicle($owner->id, $carModel->id, 'available');
        $this->createVehicle($owner->id, $carModel->id, 'rented');

        Sanctum::actingAs($masterAdmin);

        $response = $this->getJson('/api/v1/admin/users/' . $owner->id . '?vehicles_status=rented&vehicles_per_page=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.vehicles.total', 1)
            ->assertJsonPath('data.vehicles.per_page', 1)
            ->assertJsonPath('data.vehicles.data.0.status', 'rented');
    }

    public function test_support_cannot_view_admin_user_detail(): void
    {
        $support = $this->createUserWithRole('support');
        $admin = $this->createUserWithRole('admin');

        Sanctum::actingAs($support);

        $response = $this->getJson('/api/v1/admin/users/' . $admin->id);

        $response->assertForbidden();
    }

    public function test_support_index_only_returns_owner_or_renter_users(): void
    {
        $support = $this->createUserWithRole('support');
        $this->createUserWithRole('owner');
        $this->createUserWithRole('renter');
        $this->createUserWithRole('admin');

        Sanctum::actingAs($support);

        $response = $this->getJson('/api/v1/admin/users?per_page=50');

        $response->assertOk()->assertJsonPath('success', true);

        $users = $response->json('data.data');

        $this->assertNotEmpty($users);

        foreach ($users as $user) {
            $roleSlugs = array_map(static fn ($role) => $role['slug'], $user['roles'] ?? []);
            $this->assertTrue(
                in_array('owner', $roleSlugs, true) || in_array('renter', $roleSlugs, true),
                'Support should only see owner/renter users.'
            );
        }
    }

    public function test_coordinator_can_view_admin_user_detail(): void
    {
        $coordinator = $this->createUserWithRole('coordinator');
        $admin = $this->createUserWithRole('admin');

        Sanctum::actingAs($coordinator);

        $response = $this->getJson('/api/v1/admin/users/' . $admin->id);

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_show_returns_404_when_user_not_found(): void
    {
        $masterAdmin = $this->createUserWithRole('master_admin');

        Sanctum::actingAs($masterAdmin);

        $response = $this->getJson('/api/v1/admin/users/999999');

        $response->assertNotFound()->assertJsonPath('success', false);
    }

    private function createUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            [
                'name' => strtoupper($roleSlug),
                'description' => $roleSlug,
            ]
        );

        $user = User::create([
            'name' => 'User ' . $roleSlug . ' ' . uniqid('', true),
            'email' => $roleSlug . '_' . uniqid('', true) . '@example.com',
            'password' => Hash::make('secret123'),
            'phone' => null,
        ]);

        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function createCarModel(): CarModel
    {
        $now = now();

        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'suv_' . uniqid('', true),
            'display_name' => 'SUV',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fuelId = DB::table('fuels')->insertGetId([
            'name' => 'gasoline_' . uniqid('', true),
            'display_name' => 'Xăng',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $transmissionId = DB::table('transmissions')->insertGetId([
            'name' => 'auto_' . uniqid('', true),
            'display_name' => 'Tự động',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return CarModel::create([
            'category_id' => $categoryId,
            'fuel_id' => $fuelId,
            'transmission_id' => $transmissionId,
            'brand_name' => 'Toyota',
            'model_name' => 'Vios',
            'seat_count' => 5,
        ]);
    }

    private function createVehicle(int $ownerId, int $carModelId, string $status): Vehicle
    {
        return Vehicle::create([
            'owner_id' => $ownerId,
            'car_model_id' => $carModelId,
            'license_plate' => '51H-' . random_int(10000, 99999),
            'vin_number' => 'VIN' . uniqid('', true),
            'engine_number' => 'ENG' . uniqid('', true),
            'year' => 2023,
            'base_price' => 1200000,
            'parking_address' => 'Ho Chi Minh',
            'status' => $status,
        ]);
    }
}
