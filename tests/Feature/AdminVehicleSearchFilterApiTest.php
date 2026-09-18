<?php

namespace Tests\Feature;

use App\Models\CarModel;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVehicleSearchFilterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_by_status(): void
    {
        $admin = $this->createUserWithRole('master_admin');
        $owner = $this->createUserWithRole('owner');
        $carModel = $this->createCarModel();

        $this->createVehicle($owner->id, $carModel->id, 'available', '51A-11111', 'VIN-AV-001');
        $this->createVehicle($owner->id, $carModel->id, 'pending', '51A-22222', 'VIN-PD-001');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/vehicles?status=available');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'available')
            ->assertJsonPath('data.data.0.license_plate', '51A-11111');
    }

    public function test_index_searches_by_license_plate_and_vin(): void
    {
        $admin = $this->createUserWithRole('master_admin');
        $owner = $this->createUserWithRole('owner');
        $carModel = $this->createCarModel();

        $this->createVehicle($owner->id, $carModel->id, 'available', '51G-67890', 'VIN-SEARCH-001');
        $this->createVehicle($owner->id, $carModel->id, 'available', '51H-12345', 'VIN-OTHER-002');

        Sanctum::actingAs($admin);

        $licenseResponse = $this->getJson('/api/v1/admin/vehicles?search=67890');
        $licenseResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.license_plate', '51G-67890');

        $vinResponse = $this->getJson('/api/v1/admin/vehicles?search=SEARCH-001');
        $vinResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.vin_number', 'VIN-SEARCH-001');
    }

    public function test_index_search_and_status_filter_work_together(): void
    {
        $admin = $this->createUserWithRole('master_admin');
        $ownerA = $this->createUserWithRole('owner', 'Nguyen Van An', '0900000001');
        $ownerB = $this->createUserWithRole('owner', 'Tran Thi Binh', '0900000002');
        $carModel = $this->createCarModel();

        $this->createVehicle($ownerA->id, $carModel->id, 'available', '51K-11111', 'VIN-AVL-111');
        $this->createVehicle($ownerA->id, $carModel->id, 'pending', '51K-22222', 'VIN-PDG-222');
        $this->createVehicle($ownerB->id, $carModel->id, 'available', '51K-33333', 'VIN-AVL-333');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/admin/vehicles?status=available&search=Nguyen');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'available')
            ->assertJsonPath('data.data.0.owner.name', 'Nguyen Van An')
            ->assertJsonPath('data.data.0.license_plate', '51K-11111');
    }

    private function createUserWithRole(string $roleSlug, ?string $name = null, ?string $phone = null): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => $roleSlug],
            [
                'name' => strtoupper($roleSlug),
                'description' => $roleSlug,
            ]
        );

        $user = User::create([
            'name' => $name ?? ('User ' . $roleSlug . ' ' . uniqid('', true)),
            'email' => $roleSlug . '_' . uniqid('', true) . '@example.com',
            'password' => Hash::make('secret123'),
            'phone' => $phone,
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
            'display_name' => 'Xang',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $transmissionId = DB::table('transmissions')->insertGetId([
            'name' => 'auto_' . uniqid('', true),
            'display_name' => 'Tu dong',
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

    private function createVehicle(
        int $ownerId,
        int $carModelId,
        string $status,
        string $licensePlate,
        string $vinNumber
    ): Vehicle {
        return Vehicle::create([
            'owner_id' => $ownerId,
            'car_model_id' => $carModelId,
            'license_plate' => $licensePlate,
            'vin_number' => $vinNumber,
            'engine_number' => 'ENG-' . uniqid('', true),
            'year' => 2023,
            'base_price' => 1200000,
            'parking_address' => 'Ho Chi Minh',
            'status' => $status,
        ]);
    }
}
