<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminBannerApiTest extends TestCase
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

    public function test_admin_can_upload_and_create_banner(): void
    {
        Storage::fake('public');

        $admin = $this->createUserWithRole('admin');
        Sanctum::actingAs($admin);

        $file = UploadedFile::fake()->image('banner.jpg');

        $response = $this->postJson('/api/v1/admin/banners', [
            'title' => 'Summer Sale',
            'redirect_url' => 'https://example.com/summer',
            'display_order' => 1,
            'is_active' => true,
            'image' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('banners', [
            'title' => 'Summer Sale',
            'redirect_url' => 'https://example.com/summer',
        ]);
    }

    public function test_admin_can_get_banners(): void
    {
        $admin = $this->createUserWithRole('admin');
        Sanctum::actingAs($admin);

        Banner::create([
            'title' => 'Test Banner',
            'image_url' => '/storage/banners/test.jpg',
            'is_active' => true,
            'display_order' => 1
        ]);

        $response = $this->getJson('/api/v1/admin/banners');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_delete_banner(): void
    {
        $admin = $this->createUserWithRole('admin');
        Sanctum::actingAs($admin);

        $banner = Banner::create([
            'title' => 'Test Banner',
            'image_url' => '/storage/banners/test.jpg',
            'is_active' => true,
            'display_order' => 1
        ]);

        $response = $this->deleteJson('/api/v1/admin/banners/' . $banner->id);

        $response->assertOk();
        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
    }
}
