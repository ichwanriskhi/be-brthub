<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuthServiceClient;
use App\Services\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class IdentityRoleController extends Controller
{
    public function __construct(protected AuthServiceClient $idp) {}
    // ==================== WEB ADMIN ====================

    /**
     * Display all users who can be assigned roles.
     */
    public function index(Request $request)
    {
        $query = User::with('roles')
            ->withCount('roles as active_role_count');

        // Filter by search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->filled('role_id')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('roles.id', $request->role_id);
            });
        }

        $users = $query->paginate(20)->appends($request->only('search', 'role_id'));
        $allRoles = Role::orderBy('name')->get();

        return view('admin.identity-roles.index', compact('users', 'allRoles'));
    }

    /**
     * Show form to assign roles to a specific user.
     */
    public function edit(User $user)
    {
        $user->load('roles');
        $allRoles = Role::orderBy('name')->get();

        return view('admin.identity-roles.edit', compact('user', 'allRoles'));
    }

    /**
     * Update user roles via POST.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'role_ids' => 'required|array|min:1',
            'role_ids.*' => 'exists:roles,id',
            'expires_at' => 'nullable|date|after:now',
            'note' => 'nullable|string|max:500',
        ]);

        $currentUserId = auth()->id();

        try {
            DB::beginTransaction();

            // Hapus semua role lama yang belum expired
            UserRole::where('user_id', $user->id)
                ->whereNull('assigned_by_user_id')
                ->update(['is_active' => false]);

            // Assign new roles (upsert each)
            foreach ($validated['role_ids'] as $roleId) {
                UserRole::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'role_id' => $roleId,
                    ],
                    [
                        'assigned_by_user_id' => $currentUserId,
                        'expires_at' => $validated['expires_at'] ?? null,
                        'is_active' => true,
                    ]
                );
            }

            // Hapus old assigned-by-other yang tidak di-centang
            UserRole::where('user_id', $user->id)
                ->whereNotNull('assigned_by_user_id')
                ->whereNotIn('role_id', $validated['role_ids'])
                ->update(['is_active' => false]);

            DB::commit();

            return redirect()
                ->route('admin.identity-roles.edit', $user)
                ->with('success', 'Role berhasil diperbarui untuk '.$user->full_name);

        } catch (\Throwable $e) {
            DB::rollBack();

            return back()->withErrors(['error' => 'Gagal memperbarui role: '.$e->getMessage()]);
        }
    }

    /**
     * Revoke a single role from user (AJAX/POST).
     */
    public function revoke(Request $request, User $user)
    {
        $validated = $request->validate([
            'role_id' => 'required|exists:roles,id',
        ]);

        UserRole::where('user_id', $user->id)
            ->where('role_id', $validated['role_id'])
            ->update(['is_active' => false]);

        return back()->with('success', 'Role berhasil dicabut');
    }

    // ==================== API ADMIN - User Roles ====================

    /**
     * Show the active roles of a user (users table is the only identity source).
     */
    public function showRoles(User $user)
    {
        return response()->json([
            'user' => $user->only(['id', 'auth_service_uuid', 'full_name', 'email', 'phone_number']),
            'roles' => $user->roles()->get(['roles.id', 'roles.name', 'roles.label']),
        ]);
    }

    // ==================== API ADMIN - Employee Profiles ====================

    public function indexEmployeeProfiles(Request $request)
    {
        $query = EmployeeProfile::with([
            'user:id,auth_service_uuid,full_name,email,phone_number,is_active',
            'user.roles',
            'department',
            'position',
        ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('full_name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhere('employee_number', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $perPage = min(max((int) $request->integer('per_page', 20), 1), 200);

        $paginator = $query->paginate($perPage)->appends($request->all());

        // Tandai kepemilikan password dari IdP (cached, gagal-aman → null).
        $paginator->getCollection()->transform(function (EmployeeProfile $profile) {
            $profile->setAttribute('has_password', $this->resolveHasPassword($profile->user));

            return $profile;
        });

        return response()->json($paginator);
    }

    public function showEmployeeProfile($id)
    {
        return response()->json($this->loadEmployeeProfile($id));
    }

    public function storeEmployeeProfile(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id|unique:employee_profiles,user_id',
            'full_name' => 'required_without:user_id|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:30',
            'employee_number' => 'required|string|unique:employee_profiles,employee_number',
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:positions,id',
            'status' => 'nullable|in:active,inactive',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $phone = PhoneNumber::normalize($validated['phone_number'] ?? null);
        $email = isset($validated['email']) && $validated['email'] !== ''
            ? strtolower(trim($validated['email']))
            : null;

        // Identity provider dulu: user baru harus ada di Auth Service
        // sebelum profil lokal dibuat (uuid ditautkan di bawah).
        $idpUuid = null;
        if (empty($validated['user_id']) && ($email || $phone)) {
            $idpUuid = $this->provisionIdpUuid($validated['full_name'], $email, $phone);
        }

        $profile = DB::transaction(function () use ($request, $validated, $phone, $email, $idpUuid) {
            $user = $this->resolveUserForStore($validated, $email, $phone, $idpUuid);

            $profile = EmployeeProfile::create([
                'user_id' => $user->id,
                'employee_number' => $validated['employee_number'],
                'department_id' => $validated['department_id'] ?? null,
                'position_id' => $validated['position_id'] ?? null,
                'status' => $validated['status'] ?? 'active',
            ]);

            if ($request->exists('roles')) {
                $this->syncUserRoles($user, $validated['roles'] ?? [], $request->user()?->id);
            }

            return $profile;
        });

        return response()->json($this->loadEmployeeProfile($profile->id), 201);
    }

    public function updateEmployeeProfile(Request $request, $id)
    {
        $profile = EmployeeProfile::findOrFail($id);

        $validated = $request->validate([
            'employee_number' => [
                'sometimes',
                'string',
                Rule::unique('employee_profiles', 'employee_number')->ignore($profile->id),
            ],
            'department_id' => 'nullable|exists:departments,id',
            'position_id' => 'nullable|exists:positions,id',
            'status' => 'nullable|in:active,inactive',
            'full_name' => 'sometimes|string|max:255',
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($profile->user_id),
            ],
            'phone_number' => 'nullable|string|max:30',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        $phone = array_key_exists('phone_number', $validated)
            ? PhoneNumber::normalize($validated['phone_number'])
            : null;

        if ($phone) {
            $taken = User::where('phone_number', $phone)->where('id', '!=', $profile->user_id)->exists();
            if ($taken) {
                return response()->json([
                    'message' => 'Nomor telepon sudah dipakai user lain.',
                    'errors' => ['phone_number' => ['Nomor telepon sudah dipakai user lain.']],
                ], 422);
            }
        }

        // Sinkron ke Auth Service dulu: lokal hanya diubah bila IdP sukses.
        $user = $profile->user;
        $idpUuid = $user?->auth_service_uuid;
        if ($user && ! $idpUuid && ($user->email || $user->phone_number)) {
            $idpUuid = $this->provisionIdpUuid($user->full_name, $user->email, $user->phone_number);
        }

        $idpFields = [];
        if (array_key_exists('full_name', $validated)) {
            $idpFields['full_name'] = $validated['full_name'];
        }
        if (array_key_exists('email', $validated)) {
            $idpFields['email'] = $validated['email'] ? strtolower(trim($validated['email'])) : null;
        }
        if ($phone) {
            $idpFields['phone_number'] = $phone;
        }

        if ($user && $idpUuid && $idpFields !== []) {
            $synced = $this->idp->updateIdpUser($idpUuid, $idpFields);
            if (! $synced['success']) {
                Log::warning('IdP user update failed, local data untouched', [
                    'uuid' => $idpUuid,
                    'error' => $synced['error'] ?? null,
                ]);

                return response()->json([
                    'message' => 'Gagal mengubah data di Auth Service: '.($synced['error'] ?? 'unknown error').' Data lokal tidak diubah.',
                ], 502);
            }
        }

        DB::transaction(function () use ($request, $profile, $validated, $phone, $idpUuid) {
            $profileFields = [];
            foreach (['employee_number', 'department_id', 'position_id', 'status'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $profileFields[$field] = $validated[$field];
                }
            }
            if ($profileFields !== []) {
                $profile->update($profileFields);
            }

            $user = $profile->user;
            if ($user) {
                $userUpdates = [];
                if ($idpUuid && ! $user->auth_service_uuid) {
                    $userUpdates['auth_service_uuid'] = $idpUuid;
                }
                if (array_key_exists('full_name', $validated)) {
                    $userUpdates['full_name'] = $validated['full_name'];
                }
                if (array_key_exists('email', $validated)) {
                    $userUpdates['email'] = $validated['email'] ? strtolower(trim($validated['email'])) : null;
                }
                if (array_key_exists('phone_number', $validated)) {
                    $userUpdates['phone_number'] = $phone;
                }
                if ($userUpdates !== []) {
                    $user->update($userUpdates);
                }

                if ($request->exists('roles')) {
                    $this->syncUserRoles($user, $validated['roles'] ?? [], $request->user()?->id);
                }
            }
        });

        return response()->json($this->loadEmployeeProfile($profile->id));
    }

    public function destroyEmployeeProfile($id)
    {
        $profile = EmployeeProfile::findOrFail($id);
        $profile->delete();

        return response()->json(['message' => 'Employee profile deleted']);
    }

    private function loadEmployeeProfile(int|string $id): EmployeeProfile
    {
        return EmployeeProfile::with([
            'user:id,auth_service_uuid,full_name,email,phone_number,is_active',
            'user.roles',
            'department',
            'position',
        ])->findOrFail($id);
    }

    /**
     * Kepemilikan password dari Auth Service (cached 5 menit).
     * Mengembalikan null bila user belum tertaut atau IdP tak terjangkau.
     */
    protected function resolveHasPassword(?User $user): ?bool
    {
        $uuid = $user?->auth_service_uuid;
        if (! $uuid) {
            return null;
        }

        return Cache::remember("idp_has_password_{$uuid}", 300, function () use ($user) {
            $lookup = $this->idp->findUserByPhoneOrEmail($user->phone_number, $user->email);

            if (! ($lookup['success'] ?? false)) {
                return null;
            }

            return isset($lookup['user']['has_password'])
                ? (bool) $lookup['user']['has_password']
                : null;
        });
    }

    /**
     * Lookup-or-create user di Auth Service, kembalikan uuid-nya.
     *
     * Lookup dulu agar tidak duplikat; kalau belum ada, provision. Retry
     * yang aman: user IdP yatim (lokal gagal setelah IdP sukses) akan
     * ditemukan lookup dan ditautkan, bukan diduplikat.
     */
    protected function provisionIdpUuid(string $fullName, ?string $email, ?string $phone): string
    {
        $lookup = $this->idp->findUserByPhoneOrEmail($phone, $email);

        if ($lookup['success']) {
            $uuid = $lookup['user']['uuid'] ?? $lookup['user']['id'] ?? null;
            if (is_string($uuid) && $uuid !== '') {
                return $uuid;
            }
        } elseif (($lookup['error'] ?? null) !== 'not_found') {
            Log::warning('IdP user lookup failed during provisioning', ['error' => $lookup['error'] ?? null]);
            abort(response()->json([
                'message' => 'Auth Service tidak dapat dihubungi. Data lokal tidak diubah.',
            ], 502));
        }

        $created = $this->idp->createIdpUser(array_filter([
            'full_name' => $fullName,
            'email' => $email,
            'phone_number' => $phone,
        ], fn ($value) => $value !== null && $value !== ''));

        if ($created['success'] && ! empty($created['uuid'])) {
            return (string) $created['uuid'];
        }

        // Balapan: identifier dibuat pihak lain di antara lookup dan create.
        if (($created['status'] ?? null) === 409 && ! empty($created['duplicate_user']['uuid'])) {
            return (string) $created['duplicate_user']['uuid'];
        }

        Log::warning('IdP user provisioning failed', ['error' => $created['error'] ?? null]);
        abort(response()->json([
            'message' => 'Gagal membuat user di Auth Service: '.($created['error'] ?? 'unknown error').' Data lokal tidak diubah.',
        ], 502));
    }

    private function resolveUserForStore(array $validated, ?string $email, ?string $phone, ?string $idpUuid = null): User
    {
        if (! empty($validated['user_id'])) {
            $user = User::findOrFail($validated['user_id']);

            // Tautkan uuid IdP untuk user lama yang belum tertaut.
            if (! $user->auth_service_uuid && ($user->email || $user->phone_number)) {
                $user->update([
                    'auth_service_uuid' => $this->provisionIdpUuid(
                        $user->full_name,
                        $user->email,
                        $user->phone_number
                    ),
                ]);
            }

            return $user->refresh();
        }

        $user = null;
        if ($idpUuid) {
            $user = User::where('auth_service_uuid', $idpUuid)->first();
        }
        if (! $user && $email) {
            $user = User::where('email', $email)->first();
        }
        if (! $user && $phone) {
            $user = User::where('phone_number', $phone)->first();
        }

        if ($user) {
            if ($user->employeeProfile()->exists()) {
                abort(response()->json([
                    'message' => 'User ini sudah memiliki profil pegawai.',
                    'errors' => ['email' => ['User ini sudah memiliki profil pegawai.']],
                ], 422));
            }

            $user->update(array_filter([
                'auth_service_uuid' => $idpUuid ?? $user->auth_service_uuid,
                'full_name' => $validated['full_name'] ?? null,
                'email' => $email,
                'phone_number' => $phone,
            ], fn ($value) => $value !== null && $value !== ''));

            return $user->refresh();
        }

        return User::create([
            'auth_service_uuid' => $idpUuid,
            'full_name' => $validated['full_name'],
            'email' => $email,
            'phone_number' => $phone,
            'is_active' => true,
            'source' => 'local',
        ]);
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function syncUserRoles(User $user, array $roleNames, ?int $assignedBy): void
    {
        $roleIds = Role::query()
            ->whereIn('name', $roleNames)
            ->pluck('id');

        UserRole::where('user_id', $user->id)
            ->when($roleIds->isNotEmpty(), fn ($q) => $q->whereNotIn('role_id', $roleIds))
            ->when($roleIds->isEmpty(), fn ($q) => $q)
            ->update(['is_active' => false]);

        foreach ($roleIds as $roleId) {
            UserRole::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'role_id' => $roleId,
                ],
                [
                    'assigned_by_user_id' => $assignedBy,
                    'assigned_at' => now(),
                    'expires_at' => null,
                    'is_active' => true,
                ],
            );
        }
    }
}
