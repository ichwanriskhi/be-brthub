<?php

namespace App\Http\Controllers;

use App\Models\Action;
use App\Models\Category;
use App\Models\CategoryAction;
use App\Models\Department;
use App\Models\EmployeeProfile;
use App\Models\Position;
use App\Models\PriorityLevel;
use App\Models\TicketStatus;
use App\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MasterDataController extends Controller
{
    /**
     * Get all departments for dropdown
     */
    public function departments()
    {
        $departments = Department::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json($departments);
    }

    /**
     * Get all positions for dropdown, optionally filtered by department_id
     */
    public function positions(Request $request)
    {
        $query = Position::where('is_active', true);

        if ($request->has('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        $positions = $query->orderBy('hierarchy_level')
            ->orderBy('name')
            ->get(['id', 'department_id', 'code', 'name', 'hierarchy_level']);

        return response()->json($positions);
    }

    /**
     * Get all categories for dropdown (parents with children)
     */
    public function categories()
    {
        $categories = Category::whereNull('parent_category_id')
            ->where('is_active', true)
            ->with(['children' => function ($q) {
                $q->where('is_active', true)->orderBy('name');
            }])
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        return response()->json($categories);
    }

    /**
     * Get all priority levels for dropdown
     */
    public function priorities()
    {
        $priorities = PriorityLevel::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name']);

        return response()->json($priorities);
    }

    /**
     * Get all ticket types for dropdown
     */
    public function ticketTypes()
    {
        $ticketTypes = TicketType::where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'code', 'name']);

        return response()->json($ticketTypes);
    }

    /**
     * Get all ticket statuses for dropdown
     */
    public function statuses()
    {
        $statuses = TicketStatus::orderBy('sort_order')
            ->get(['id', 'code', 'name', 'is_terminal']);

        return response()->json($statuses);
    }

    /**
     * Get all master data in one request (for initial load)
     */
    public function all()
    {
        // `include_inactive=1` menampilkan data nonaktif juga — dipakai halaman
        // admin Master Data. Default tetap hanya yang aktif (dropdown publik).
        $withInactive = (bool) request()->query('include_inactive');
        $categoryColumns = ['id', 'parent_category_id', 'code', 'name', 'is_active'];
        if (Schema::hasColumn('categories', 'description')) {
            $categoryColumns[] = 'description';
        }

        return response()->json([
            'departments' => Department::query()
                ->when(! $withInactive, fn ($q) => $q->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'description', 'sort_order', 'is_active']),

            'positions' => Position::query()
                ->when(! $withInactive, fn ($q) => $q->where('is_active', true))
                ->orderBy('hierarchy_level')
                ->orderBy('name')
                ->get(['id', 'department_id', 'code', 'name', 'hierarchy_level', 'is_active']),

            'categories' => Category::whereNull('parent_category_id')
                ->where('is_active', true)
                ->with(['children' => function ($q) {
                    $q->where('is_active', true)->orderBy('name');
                }])
                ->orderBy('name')
                ->get(['id', 'code', 'name']),

            'raw_categories' => Category::query()
                ->when(! $withInactive, fn ($q) => $q->where('is_active', true))
                ->orderBy('name')
                ->get($categoryColumns),

            'priorities' => PriorityLevel::query()
                ->when(! $withInactive, fn ($q) => $q->where('is_active', true))
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name', 'sort_order', 'is_active']),

            'ticketTypes' => TicketType::query()
                ->when(! $withInactive, fn ($q) => $q->where('is_active', true))
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'is_active']),

            'statuses' => TicketStatus::orderBy('sort_order')
                ->get(['id', 'code', 'name', 'is_terminal']),

            'actions' => Action::query()
                ->when(! $withInactive, fn ($q) => $q->where('is_active', true))
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'description', 'is_active']),

            'category_actions' => CategoryAction::with('action')
                ->get(['category_id', 'action_id', 'is_recommended']),
        ]);
    }

    /**
     * Daftar mapping kategori → aksi untuk editor matriks admin.
     * GET /api/admin/master/category-actions?category_id=
     */
    public function categoryActions(Request $request)
    {
        $request->validate([
            'category_id' => 'nullable|integer|exists:categories,id',
        ]);

        $query = CategoryAction::with('action:id,code,name,description,is_active')
            ->orderBy('category_id')
            ->orderBy('action_id');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        return response()->json($query->get());
    }

    /**
     * Pasang aksi ke kategori.
     * POST /api/admin/master/category-actions
     */
    public function attachCategoryAction(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'action_id' => [
                'required',
                'integer',
                'exists:actions,id',
                Rule::unique('category_actions', 'action_id')->where(
                    fn ($q) => $q->where('category_id', $request->input('category_id'))
                ),
            ],
            'is_recommended' => 'boolean',
        ]);

        $mapping = CategoryAction::create([
            'category_id' => $validated['category_id'],
            'action_id' => $validated['action_id'],
            'is_recommended' => $validated['is_recommended'] ?? false,
        ]);

        return response()->json($mapping->load('action:id,code,name'), 201);
    }

    /**
     * Ubah flag rekomendasi satu mapping.
     * PUT /api/admin/master/category-actions/{category}/{action}
     */
    public function updateCategoryAction(Request $request, string $category, string $action)
    {
        // Pivot ber-PK komposit tanpa kolom `id` — `$mapping->update()` akan
        // menghasilkan `where id is null`. Update lewat query builder.
        $exists = CategoryAction::where('category_id', $category)
            ->where('action_id', $action)
            ->exists();

        abort_unless($exists, 404);

        $validated = $request->validate([
            'is_recommended' => 'required|boolean',
        ]);

        CategoryAction::where('category_id', $category)
            ->where('action_id', $action)
            ->update(['is_recommended' => $validated['is_recommended']]);

        $mapping = CategoryAction::where('category_id', $category)
            ->where('action_id', $action)
            ->with('action:id,code,name')
            ->firstOrFail();

        return response()->json($mapping);
    }

    /**
     * Lepas aksi dari kategori (aksi master-nya tidak ikut terhapus).
     * DELETE /api/admin/master/category-actions/{category}/{action}
     */
    public function detachCategoryAction(string $category, string $action)
    {
        // Sama seperti update: tanpa kolom `id`, hapus lewat query builder.
        $deleted = CategoryAction::where('category_id', $category)
            ->where('action_id', $action)
            ->delete();

        abort_unless($deleted, 404);

        return response()->json(null, 204);
    }

    /**
     * Daftar pegawai untuk dropdown assign handler.
     *
     * Bila `department_id` diberikan, hanya pegawai di departemen tersebut yang
     * dikembalikan. Bila `role` diberikan (mis. `role=handler`), hanya pegawai
     * yang user-nya memiliki role tersebut yang dikembalikan — halaman unit
     * hanya boleh menugaskan handler ber-role `handler` di departemen tiket.
     */
    public function employees(Request $request)
    {
        $request->validate([
            'department_id' => 'nullable|integer|exists:departments,id',
            'role' => 'nullable|string|exists:roles,name',
        ]);

        $query = EmployeeProfile::query()
            ->with(['user:id,full_name,email,phone_number', 'position:id,name'])
            ->whereHas('user', fn ($q) => $q->where('is_active', true));

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('role')) {
            $query->whereHas('user.roles', fn ($q) => $q->where('name', $request->string('role')));
        }

        $employees = $query->orderBy('department_id')
            ->orderBy('id')
            ->get(['id', 'user_id', 'department_id', 'position_id']);

        return response()->json($employees->map(fn ($e) => [
            'id' => $e->id,
            'full_name' => $e->user?->full_name,
            'email' => $e->user?->email,
            'phone_number' => $e->user?->phone_number,
            'department_id' => $e->department_id,
            'position_name' => $e->position?->name,
        ]));
    }

    // ==================== ADMIN MASTER DATA CRUD ====================

    /**
     * Create a new master data record.
     * POST /api/admin/master/{type}
     */
    public function store(Request $request, string $type)
    {
        $this->normalizeMasterPayload($request);
        $validated = $this->validateMasterData($request, $type, true);
        $model = $this->getModelClass($type)::create($validated);

        return response()->json($model, 201);
    }

    /**
     * Update a master data record.
     * PUT /api/admin/master/{type}/{id}
     */
    public function update(Request $request, string $type, string $id)
    {
        $model = $this->getModelClass($type)::findOrFail($id);

        // `code` action adalah identitas yang dipakai reviewer (`resolveActionId`
        // menerima code) — mengubahnya diam-diam akan mengaburkan jejak tiket
        // lama. Tolak eksplisit, bukan diabaikan. Sama untuk prioritas & tipe:
        // code-nya dipakai sebagai key filter, badge, dan resolve di banyak tempat.
        if (in_array($type, ['action', 'priority', 'ticket_type']) && $request->exists('code') && $request->input('code') !== $model->code) {
            throw ValidationException::withMessages([
                'code' => ['Kode tidak dapat diubah setelah dibuat.'],
            ]);
        }

        $this->normalizeMasterPayload($request);
        $validated = $this->validateMasterData($request, $type, false, $id);
        $model->update($validated);

        return response()->json($model);
    }

    /**
     * Delete a master data record.
     * DELETE /api/admin/master/{type}/{id}
     */
    public function destroy(string $type, string $id)
    {
        $model = $this->getModelClass($type)::findOrFail($id);

        // Prevent deletion if referenced by tickets
        if ($type === 'category') {
            if ($model->tickets()->exists()) {
                return response()->json([
                    'message' => 'Kategori sedang digunakan oleh tiket dan tidak dapat dihapus.',
                ], 409);
            }
        }

        if ($type === 'action') {
            $ticketCount = DB::table('tickets')
                ->where('action_id', $model->id)
                ->count();
            if ($ticketCount > 0) {
                return response()->json([
                    'message' => "Aksi ini sudah dipilih pada {$ticketCount} tiket dan tidak dapat dihapus.",
                ], 409);
            }

            $mappingCount = CategoryAction::where('action_id', $model->id)->count();
            if ($mappingCount > 0) {
                return response()->json([
                    'message' => "Aksi ini masih terpasang pada {$mappingCount} kategori. Lepaskan dulu sebelum menghapus.",
                ], 409);
            }
        }

        if ($type === 'priority') {
            $ticketCount = DB::table('tickets')
                ->where('priority_id', $model->id)
                ->count();
            if ($ticketCount > 0) {
                return response()->json([
                    'message' => "Prioritas ini dipakai {$ticketCount} tiket dan tidak dapat dihapus.",
                ], 409);
            }
        }

        if ($type === 'ticket_type') {
            $ticketCount = DB::table('tickets')
                ->where('ticket_type_id', $model->id)
                ->count();
            if ($ticketCount > 0) {
                return response()->json([
                    'message' => "Tipe ini dipakai {$ticketCount} tiket dan tidak dapat dihapus.",
                ], 409);
            }
        }

        $model->delete();

        return response()->json(null, 204);
    }

    /**
     * Get model class by type.
     */
    private function getModelClass(string $type): string
    {
        return match ($type) {
            'category' => Category::class,
            'department' => Department::class,
            'position' => Position::class,
            'action' => Action::class,
            'priority' => PriorityLevel::class,
            'ticket_type' => TicketType::class,
            default => abort(400, 'Tipe master data tidak valid.'),
        };
    }

    /**
     * Map camelCase dari frontend ke nama kolom snake_case.
     */
    private function normalizeMasterPayload(Request $request): void
    {
        $aliases = [
            'parentCategoryId' => 'parent_category_id',
            'departmentId' => 'department_id',
            'hierarchyLevel' => 'hierarchy_level',
            'isActive' => 'is_active',
            'sortOrder' => 'sort_order',
        ];

        foreach ($aliases as $camel => $snake) {
            if ($request->exists($camel) && ! $request->exists($snake)) {
                $request->merge([$snake => $request->input($camel)]);
            }
        }

        if ($request->exists('parent_category_id') && $request->input('parent_category_id') === '') {
            $request->merge(['parent_category_id' => null]);
        }
    }

    /**
     * Validation rules per master data type.
     */
    private function validateMasterData(Request $request, string $type, bool $isCreate, ?string $ignoreId = null): array
    {
        $ignore = $ignoreId !== null ? (int) $ignoreId : null;

        $rules = match ($type) {
            'category' => [
                'name' => 'required|string|max:255',
                'code' => ['required', 'string', 'max:100', Rule::unique('categories', 'code')->ignore($ignore)],
                'description' => 'nullable|string',
                'parent_category_id' => array_values(array_filter([
                    'nullable',
                    'integer',
                    Rule::exists('categories', 'id')->where(fn ($q) => $q->whereNull('parent_category_id')),
                    $ignore ? Rule::notIn([$ignore]) : null,
                ])),
                'is_active' => 'boolean',
            ],
            'department' => [
                'name' => 'required|string|max:255',
                'code' => ['required', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($ignore)],
                'description' => 'nullable|string',
                'sort_order' => 'nullable|integer|min:0',
                'is_active' => 'boolean',
            ],
            'position' => [
                'name' => 'required|string|max:255',
                'code' => ['required', 'string', 'max:50', Rule::unique('positions', 'code')->ignore($ignore)],
                'department_id' => ['required', 'integer', 'exists:departments,id'],
                'hierarchy_level' => 'required|integer|min:0',
                'is_active' => 'boolean',
            ],
            'action' => [
                'name' => 'required|string|max:255',
                'code' => ['required', 'string', 'max:50', Rule::unique('actions', 'code')->ignore($ignore)],
                'description' => 'nullable|string',
                'is_active' => 'boolean',
            ],
            'priority' => [
                'name' => 'required|string|max:255',
                'code' => ['required', 'string', 'max:10', Rule::unique('priority_levels', 'code')->ignore($ignore)],
                'sort_order' => 'nullable|integer|min:0',
                'is_active' => 'boolean',
            ],
            'ticket_type' => [
                'name' => 'required|string|max:255',
                'code' => ['required', 'string', 'max:50', Rule::unique('ticket_types', 'code')->ignore($ignore)],
                'is_active' => 'boolean',
            ],
            default => abort(400, 'Tipe master data tidak valid.'),
        };

        $validated = $request->validate($rules);

        if ($type === 'category' && $request->exists('parent_category_id')) {
            $validated['parent_category_id'] = $request->input('parent_category_id');
        }

        if ($type === 'category' && ! $isCreate && ! empty($validated['parent_category_id'])) {
            $hasChildren = Category::where('parent_category_id', $ignore)->exists();
            if ($hasChildren) {
                throw ValidationException::withMessages([
                    'parent_category_id' => ['Kategori yang memiliki sub kategori tidak dapat diubah menjadi sub kategori.'],
                ]);
            }
        }

        return $validated;
    }
}
