<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PositionSeeder extends Seeder
{
    private const LEVEL_EXECUTIVE = 1; // Top Executive

    private const LEVEL_OPERATIONS = 2; // General / Ops Management

    private const LEVEL_HEAD = 3; // Department Head / Senior

    private const LEVEL_SUPERVISOR = 4;

    private const LEVEL_STAFF = 5; // Staff / Specialist

    public function run(): void
    {
        // department_code => [[position_code, position_name, hierarchy_level], ...]
        $positionsByDepartment = [
            'EXECUTIVE' => [
                ['KOMISARIS',         'Komisaris',         self::LEVEL_EXECUTIVE],
                ['DIREKTUR_UTAMA',    'Direktur Utama',    self::LEVEL_EXECUTIVE],
                ['DIREKTUR_KEUANGAN', 'Direktur Keuangan', self::LEVEL_EXECUTIVE],
            ],
            'OPERATION' => [
                ['MANAGER_OPERASIONAL', 'Manager Operational', self::LEVEL_OPERATIONS],
            ],
            'ENGINEERING' => [
                ['MANAGER_TEKNIK',     'Manager Teknik',      self::LEVEL_HEAD],
                ['SPV_TEKNIK',         'Supervisor Teknik',   self::LEVEL_SUPERVISOR],
                ['SPV_MACHINING',      'Supervisor Machining', self::LEVEL_SUPERVISOR],
                ['SPV_PORTING',        'Supervisor Porting',  self::LEVEL_SUPERVISOR],
                ['MECHANICAL_ENGINEER', 'Mechanical Engineer', self::LEVEL_STAFF],
            ],
            'PRODUCTION' => [
                ['MANAGER_PRODUKSI', 'Manager Produksi',    self::LEVEL_HEAD],
                ['SPV_PRODUKSI',     'Supervisor Produksi', self::LEVEL_SUPERVISOR],
            ],
            'RND' => [
                ['SENIOR_MANAGER_RND', 'Senior Manager R&D', self::LEVEL_HEAD],
                ['MANAGER_RND',        'Manager R&D',        self::LEVEL_HEAD],
                ['SUPERVISOR_RND',     'Supervisor R&D',     self::LEVEL_SUPERVISOR],
            ],
            'FINISH_GOOD' => [
                ['MANAGER_FINISH_GOOD',  'Manager Finish Good & Sourcing', self::LEVEL_HEAD],
                ['SPV_FINISH_GOOD',      'Supervisor Finish Good',         self::LEVEL_SUPERVISOR],
                ['SPV_SOURCING',         'Supervisor Sourcing',            self::LEVEL_SUPERVISOR],
                ['SPV_PACKING_ONLINE',   'Supervisor Packing Online',      self::LEVEL_SUPERVISOR],
            ],
            'RAW_MATERIAL' => [
                ['MANAGER_RAW_MATERIAL', 'Manager Raw Material & Sourcing', self::LEVEL_HEAD],
            ],
            'MARKETING' => [
                ['SPV_MARKETING', 'Supervisor Marketing', self::LEVEL_SUPERVISOR],
            ],
            'FINANCE' => [
                ['SPV_FINANCE', 'Supervisor Finance', self::LEVEL_SUPERVISOR],
            ],
            'HRD' => [
                ['SPV_HRD', 'Supervisor HRD', self::LEVEL_SUPERVISOR],
            ],
            'MULTIMEDIA' => [
                ['MULTIMEDIA_STAFF', 'Multimedia', self::LEVEL_STAFF],
            ],
            'CUSTOMER_CARE' => [
                ['CLAIM_SERVICE', 'Claim Service', self::LEVEL_STAFF],
            ],
        ];

        foreach ($positionsByDepartment as $departmentCode => $positions) {
            $departmentId = DB::table('departments')->where('code', $departmentCode)->value('id')
                ?? throw new \RuntimeException("Department '{$departmentCode}' belum ada. Jalankan DepartmentSeeder dulu.");

            foreach ($positions as [$code, $name, $hierarchyLevel]) {
                DB::table('positions')->updateOrInsert(
                    ['code' => $code],
                    [
                        'department_id' => $departmentId,
                        'name' => $name,
                        'hierarchy_level' => $hierarchyLevel,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }
}
