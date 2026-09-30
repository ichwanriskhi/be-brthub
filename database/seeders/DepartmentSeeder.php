<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // Urutan array = sort_order
        $departments = [
            'EXECUTIVE' => 'Eksekutif & Direksi',
            'OPERATION' => 'Operasional',
            'FINANCE' => 'Keuangan (Finance)',
            'ENGINEERING' => 'Teknik & Engineering',
            'PRODUCTION' => 'Produksi',
            'RND' => 'Research & Development (R&D)',
            'FINISH_GOOD' => 'Finish Good & Sourcing',
            'RAW_MATERIAL' => 'Raw Material & Sourcing',
            'MARKETING' => 'Marketing',
            'HRD' => 'Human Resources Department (HRD)',
            'MULTIMEDIA' => 'Multimedia & Kreatif',
            'CUSTOMER_CARE' => 'Customer Care / Service',
        ];

        $sortOrder = 1;

        foreach ($departments as $code => $name) {
            DB::table('departments')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'sort_order' => $sortOrder++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
