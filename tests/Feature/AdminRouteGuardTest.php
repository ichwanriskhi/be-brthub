<?php

use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    Http::fake([
        '*api/auth/me*' => Http::response([
            'success' => true,
            'data' => [
                'id' => 'auth-uuid-test',
                'full_name' => 'Test User',
                'email' => 'test@example.com',
                'phone_number' => '081234567890',
            ],
        ]),
    ]);
});

/**
 * Assign role admin ke user yang di-sync dari Auth Service (token yang
 * di-fake di atas selalu me-resolve ke user yang sama).
 */
function grantAdminRole(): void
{
    $role = Role::create(['name' => 'admin', 'label' => 'Admin']);

    $userId = User::where('auth_service_uuid', 'auth-uuid-test')->firstOrFail()->id;

    UserRole::create([
        'user_id' => $userId,
        'role_id' => $role->id,
        'is_active' => true,
    ]);
}

test('reporter cannot access admin dashboard summary', function () {
    $response = $this->getJson('/api/admin/dashboard-summary', [
        'Authorization' => 'Bearer test-token',
    ]);

    $response->assertStatus(403);
    $response->assertJsonPath('success', false);
});

test('admin can access admin dashboard summary', function () {
    $headers = ['Authorization' => 'Bearer test-token'];

    // Trigger sync user dulu via request reporter, lalu beri role admin.
    $this->getJson('/api/admin/dashboard-summary', $headers)->assertStatus(403);

    grantAdminRole();

    // Roles di-resolve per request (tanpa session di API), jadi request
    // berikutnya langsung melihat role baru.
    $this->getJson('/api/admin/dashboard-summary', $headers)->assertStatus(200);
});

test('tickets cannot be deleted via api', function () {
    $this->deleteJson('/api/auth/tickets/1', [], [
        'Authorization' => 'Bearer test-token',
    ])->assertStatus(405);
});
