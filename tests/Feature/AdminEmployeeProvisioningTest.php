<?php

use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * Fake seluruh Auth Service:
 * - /api/auth/me ................. user admin (untuk auth.authservice)
 * - /oauth/token ................. service token
 * - GET  /api/v1/admin/users ..... $lookup ('found' | 'missing')
 * - POST /api/v1/admin/users ..... $create ('created' | 'conflict' | 'error')
 * - PATCH /api/v1/admin/users/* .. $update ('updated' | 'error')
 */
function fakeIdp(string $lookup = 'missing', string $create = 'created', string $update = 'updated', ?bool $lookupHasPassword = null): void
{
    Http::fake(function (Request $request) use ($lookup, $create, $update, $lookupHasPassword) {
        $url = (string) $request->url();
        $method = $request->method();

        if (str_ends_with($url, '/api/auth/me')) {
            return Http::response([
                'success' => true,
                'data' => [
                    'id' => 'admin-uuid',
                    'full_name' => 'Admin Test',
                    'email' => 'admin@example.com',
                    'phone_number' => '081000000001',
                ],
            ]);
        }

        if (str_ends_with($url, '/oauth/token')) {
            return Http::response(['access_token' => 'svc-token', 'token_type' => 'Bearer']);
        }

        if (str_contains($url, '/api/v1/admin/')) {
            if ($method === 'GET') {
                if ($lookup === 'found' || $lookupHasPassword !== null) {
                    return Http::response(['success' => true, 'data' => array_filter([
                        'uuid' => 'idp-uuid-1', 'id' => 'idp-uuid-1',
                        'full_name' => 'Existing User',
                        'phone_number' => '+6281234567890', 'email' => 'existing@example.com',
                        'has_password' => $lookupHasPassword,
                    ], fn ($v) => $v !== null)]);
                }

                return Http::response(['success' => false, 'message' => 'User not found.'], 404);
            }

            if ($method === 'POST') {
                if ($create === 'conflict') {
                    return Http::response([
                        'success' => false, 'error' => 'already_exists',
                        'message' => 'Phone number already belongs to another user.',
                        'data' => ['user' => ['uuid' => 'idp-uuid-race', 'id' => 'idp-uuid-race']],
                    ], 409);
                }
                if ($create === 'error') {
                    return Http::response(['success' => false, 'message' => 'IdP down'], 500);
                }

                return Http::response(['success' => true, 'data' => [
                    'uuid' => 'idp-uuid-new', 'id' => 'idp-uuid-new',
                    'full_name' => 'Pegawai Baru',
                    'phone_number' => '+6281234567890', 'email' => 'pegawai@example.com',
                ]], 201);
            }

            if ($method === 'PATCH') {
                return $update === 'error'
                    ? Http::response(['success' => false, 'message' => 'IdP down'], 500)
                    : Http::response(['success' => true, 'data' => ['uuid' => 'idp-uuid-1']]);
            }

            if ($method === 'POST' && str_contains($url, 'password-link')) {
                return Http::response(['success' => true, 'data' => ['type' => 'reset', 'url' => 'https://example.test/reset']]);
            }
        }

        return Http::response(null, 500);
    });
}

function grantLocalAdmin(): void
{
    $admin = User::where('auth_service_uuid', 'admin-uuid')->firstOrFail();
    $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
    UserRole::create(['user_id' => $admin->id, 'role_id' => $role->id, 'is_active' => true]);
}

function staffHeaders(): array
{
    return ['Authorization' => 'Bearer staff-token', 'Accept' => 'application/json'];
}

test('store provisions idp user and links uuid', function () {
    fakeIdp();
    $this->getJson('/api/admin/employee-profiles', staffHeaders());
    grantLocalAdmin();

    $response = $this->postJson('/api/admin/employee-profiles', [
        'full_name' => 'Pegawai Baru',
        'email' => 'pegawai@example.com',
        'phone_number' => '081234567890',
        'employee_number' => 'EMP-001',
    ], staffHeaders());

    $response->assertCreated();
    expect(User::where('auth_service_uuid', 'idp-uuid-new')->exists())->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains((string) $r->url(), '/api/v1/admin/'));
});

test('store links existing idp user instead of duplicating', function () {
    fakeIdp(lookup: 'found');
    $this->getJson('/api/admin/employee-profiles', staffHeaders());
    grantLocalAdmin();

    $this->postJson('/api/admin/employee-profiles', [
        'full_name' => 'Existing User',
        'email' => 'existing@example.com',
        'phone_number' => '081234567890',
        'employee_number' => 'EMP-002',
    ], staffHeaders())->assertCreated();

    expect(User::where('auth_service_uuid', 'idp-uuid-1')->exists())->toBeTrue();
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) $r->url(), '/users'));
});

test('store fails loudly when idp is down, nothing stored locally', function () {
    fakeIdp(create: 'error');
    $this->getJson('/api/admin/employee-profiles', staffHeaders());
    grantLocalAdmin();

    $this->postJson('/api/admin/employee-profiles', [
        'full_name' => 'Pegawai Baru',
        'email' => 'pegawai@example.com',
        'phone_number' => '081234567890',
        'employee_number' => 'EMP-003',
    ], staffHeaders())->assertStatus(502);

    expect(User::where('email', 'pegawai@example.com')->exists())->toBeFalse();
});

test('update pushes changes to idp first', function () {
    fakeIdp();
    $this->getJson('/api/admin/employee-profiles', staffHeaders());
    grantLocalAdmin();

    $user = User::create([
        'auth_service_uuid' => 'idp-uuid-1',
        'full_name' => 'Nama Lama',
        'email' => 'lama@example.com',
        'is_active' => true,
        'source' => 'local',
    ]);
    $profile = EmployeeProfile::create(['user_id' => $user->id, 'employee_number' => 'EMP-010']);

    $this->putJson("/api/admin/employee-profiles/{$profile->id}", [
        'full_name' => 'Nama Baru',
    ], staffHeaders())->assertOk();

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains((string) $r->url(), 'idp-uuid-1'));
    expect($user->refresh()->full_name)->toBe('Nama Baru');
});

test('update leaves local untouched when idp fails', function () {
    fakeIdp(update: 'error');
    $this->getJson('/api/admin/employee-profiles', staffHeaders());
    grantLocalAdmin();

    $user = User::create([
        'auth_service_uuid' => 'idp-uuid-1',
        'full_name' => 'Nama Lama',
        'email' => 'lama@example.com',
        'is_active' => true,
        'source' => 'local',
    ]);
    $profile = EmployeeProfile::create(['user_id' => $user->id, 'employee_number' => 'EMP-011']);

    $this->putJson("/api/admin/employee-profiles/{$profile->id}", [
        'full_name' => 'Nama Baru',
    ], staffHeaders())->assertStatus(502);

    expect($user->refresh()->full_name)->toBe('Nama Lama');
});

test('password link maps local id to idp uuid', function () {
    fakeIdp();
    $this->getJson('/api/admin/employee-profiles', staffHeaders());
    grantLocalAdmin();

    $linked = User::create([
        'auth_service_uuid' => 'idp-uuid-1',
        'full_name' => 'Linked User',
        'email' => 'linked@example.com',
        'is_active' => true,
        'source' => 'local',
    ]);
    $unlinked = User::create([
        'full_name' => 'Legacy User',
        'email' => 'legacy@example.com',
        'is_active' => true,
        'source' => 'local',
    ]);

    // Belum tertaut → 422 dengan pesan yang jelas.
    $this->postJson("/api/admin/users/{$unlinked->id}/reset-password-link", [], staffHeaders())
        ->assertStatus(422)
        ->assertJsonPath('success', false);

    // Tertaut → diteruskan ke IdP memakai uuid (bukan id lokal).
    $this->postJson("/api/admin/users/{$linked->id}/reset-password-link", [], staffHeaders())
        ->assertOk()
        ->assertJsonPath('success', true);
    Http::assertSent(fn (Request $r) => str_contains((string) $r->url(), 'idp-uuid-1'));
});

test('index enriches profiles with idp has_password', function () {
    fakeIdp(lookupHasPassword: true);
    $this->getJson('/api/admin/employee-profiles', staffHeaders());
    grantLocalAdmin();

    $user = User::create([
        'auth_service_uuid' => 'idp-uuid-1',
        'full_name' => 'Linked User',
        'email' => 'existing@example.com',
        'phone_number' => '+6281234567890',
        'is_active' => true,
        'source' => 'local',
    ]);
    $unlinked = User::create([
        'full_name' => 'Legacy User',
        'email' => 'legacy2@example.com',
        'is_active' => true,
        'source' => 'local',
    ]);
    EmployeeProfile::create(['user_id' => $user->id, 'employee_number' => 'EMP-020']);
    EmployeeProfile::create(['user_id' => $unlinked->id, 'employee_number' => 'EMP-021']);

    $response = $this->getJson('/api/admin/employee-profiles?per_page=50', staffHeaders());
    $response->assertOk();

    $rows = collect($response->json('data'));
    expect($rows->firstWhere('employee_number', 'EMP-020')['has_password'])->toBeTrue();
    expect($rows->firstWhere('employee_number', 'EMP-021')['has_password'])->toBeNull();
});

test('setup link forwards channel to idp', function () {
    fakeIdp();
    $this->getJson('/api/admin/employee-profiles', staffHeaders());
    grantLocalAdmin();

    $user = User::create([
        'auth_service_uuid' => 'idp-uuid-1',
        'full_name' => 'Linked User',
        'email' => 'existing@example.com',
        'is_active' => true,
        'source' => 'local',
    ]);

    $this->postJson("/api/admin/users/{$user->id}/setup-password-link", ['channel' => 'email'], staffHeaders())
        ->assertOk();
    Http::assertSent(fn (Request $r) => str_contains((string) $r->url(), 'setup-password-link')
        && ($r->data()['channel'] ?? null) === 'email');

    $this->postJson("/api/admin/users/{$user->id}/setup-password-link", ['channel' => 'sms'], staffHeaders())
        ->assertStatus(422);
});
