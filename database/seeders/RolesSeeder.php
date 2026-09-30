<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'admin',
                'label' => 'Administrator',
                'description' => 'Full access to all BRTHUB features and data',
                'is_system' => true,
            ],
            [
                'name' => 'reviewer',
                'label' => 'Reviewer',
                'description' => 'Can review and approve/reject tickets',
                'is_system' => false,
            ],
            [
                'name' => 'handler',
                'label' => 'Handler',
                'description' => 'Can handle and resolve assigned tickets',
                'is_system' => false,
            ],
            [
                'name' => 'unit',
                'label' => 'Unit',
                'description' => 'Department unit member with specific access scope',
                'is_system' => false,
            ],
        ];

        DB::table('roles')->upsert(
            $roles,
            ['name'],
            ['label', 'description', 'is_system', 'updated_at'],
        );
    }
}
