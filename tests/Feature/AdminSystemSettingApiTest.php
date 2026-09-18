<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use App\Models\SystemSetting;

class AdminSystemSettingApiTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_admin_can_get_all_system_settings(): void
    {
        $admin = $this->createUserWithRole('admin');
        Sanctum::actingAs($admin);

        SystemSetting::create(['setting_key' => 'commissionRate', 'setting_value' => '15.5']);

        $response = $this->getJson('/api/v1/admin/system-settings');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.commissionRate.value', '15.5');
    }

    public function test_admin_can_batch_update_system_settings(): void
    {
        $admin = $this->createUserWithRole('admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/system-settings/batch', [
            'settings' => [
                'commissionRate' => 20.0,
                'maintenanceMode' => true
            ]
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'commissionRate',
            'setting_value' => '20'
        ]);

        $this->assertDatabaseHas('system_settings', [
            'setting_key' => 'maintenanceMode',
            'setting_value' => 'true'
        ]);
    }
}
