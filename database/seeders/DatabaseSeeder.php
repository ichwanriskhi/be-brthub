<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed master roles (admin, reviewer, handler, unit, reporter)
        $this->call([
            RolesSeeder::class,
            CategorySeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            WorkflowMasterSeeder::class,
            ActionSeeder::class,
        ]);

        // 2. Create first admin user (via Auth Service later)
        $admin = User::updateOrCreate(
            ['auth_service_uuid' => 'super-admin-uuid'],
            [
                'full_name' => 'Super Admin',
                'phone_number' => '+6281234567890',
                'source' => 'local',
            ],
        );

        // 3. Give the admin user the admin role so the role middleware can be exercised locally
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');

        if ($adminRoleId) {
            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $admin->id, 'role_id' => $adminRoleId],
                ['is_active' => true, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
