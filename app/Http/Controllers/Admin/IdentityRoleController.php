<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeProfile;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IdentityRoleController extends Controller
{
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

        return response()->json($query->paginate($perPage)->appends($request->all()));
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

        $profile = DB::transaction(function () use ($request, $validated, $phone, $email) {
            $user = $this->resolveUserForStore($validated, $email, $phone);

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

        DB::transaction(function () use ($request, $profile, $validated, $phone) {
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
     * @param  array<string, mixed>  $validated
     */
    private function resolveUserForStore(array $validated, ?string $email, ?string $phone): User
    {
        if (! empty($validated['user_id'])) {
            return User::findOrFail($validated['user_id']);
        }

        $user = null;
        if ($email) {
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
                'full_name' => $validated['full_name'] ?? null,
                'email' => $email,
                'phone_number' => $phone,
            ], fn ($value) => $value !== null && $value !== ''));

            return $user->refresh();
        }

        return User::create([
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
