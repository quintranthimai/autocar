<?php

namespace Tests\Feature;

use App\Models\StaticPage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStaticPageApiTest extends TestCase
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

    public function test_admin_can_create_static_page(): void
    {
        $admin = $this->createUserWithRole('admin');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/static-pages', [
            'title' => 'Privacy Policy',
            'slug' => 'privacy-policy',
            'content' => '<h1>Privacy Policy</h1>',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('static_pages', [
            'title' => 'Privacy Policy',
            'slug' => 'privacy-policy',
        ]);
    }

    public function test_admin_can_update_static_page(): void
    {
        $admin = $this->createUserWithRole('admin');
        Sanctum::actingAs($admin);

        $page = StaticPage::create([
            'title' => 'Old Title',
            'slug' => 'old-title',
            'content' => 'old content',
            'updated_by_admin_id' => $admin->id
        ]);

        $response = $this->putJson('/api/v1/admin/static-pages/' . $page->id, [
            'title' => 'New Title',
            'slug' => 'new-title',
            'content' => 'new content',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('static_pages', [
            'id' => $page->id,
            'title' => 'New Title',
        ]);
    }

    public function test_admin_can_delete_static_page(): void
    {
        $admin = $this->createUserWithRole('admin');
        Sanctum::actingAs($admin);

        $page = StaticPage::create([
            'title' => 'To Be Deleted',
            'slug' => 'to-be-deleted',
            'content' => 'content',
            'updated_by_admin_id' => $admin->id
        ]);

        $response = $this->deleteJson('/api/v1/admin/static-pages/' . $page->id);

        $response->assertOk();

        $this->assertDatabaseMissing('static_pages', [
            'id' => $page->id,
        ]);
    }
}
