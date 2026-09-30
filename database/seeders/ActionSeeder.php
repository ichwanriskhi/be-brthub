<?php

namespace Database\Seeders;

use App\Models\Action;
use App\Models\Category;
use Illuminate\Database\Seeder;

class ActionSeeder extends Seeder
{
    public function run(): void
    {
        // ── Master actions ──────────────────────────────────────────────────
        $actions = [
            ['code' => 'RETURN_AND_REPLACE', 'name' => 'Return & Replace', 'description' => 'Kembalikan barang yang salah dan kirim barang pengganti.'],
            ['code' => 'REPLACE_ONLY', 'name' => 'Replace Only', 'description' => 'Kirim barang pengganti tanpa pengembalian barang.'],
            ['code' => 'ADDITIONAL_SHIPMENT', 'name' => 'Additional Shipment', 'description' => 'Kirim tambahan barang untuk melengkapi pesanan.'],
            ['code' => 'SO_CORRECTION', 'name' => 'SO Correction', 'description' => 'Perbaiki Sales Order yang salah.'],
        ];

        foreach ($actions as $a) {
            Action::updateOrCreate(['code' => $a['code']], $a);
        }

        // ── Mapping category (leaf only) → actions ──────────────────────────
        $mapping = [
            // Klaim Distribusi subcategories
            'KLAIM_DISTRIBUSI_SALAH_KIRIM' => [
                ['code' => 'RETURN_AND_REPLACE', 'recommended' => true],
                ['code' => 'REPLACE_ONLY', 'recommended' => false],
            ],
            'KLAIM_DISTRIBUSI_SALAH_SO' => [
                ['code' => 'SO_CORRECTION', 'recommended' => true],
                ['code' => 'RETURN_AND_REPLACE', 'recommended' => false],
            ],
            'KLAIM_DISTRIBUSI_KURANG_KIRIM' => [
                ['code' => 'ADDITIONAL_SHIPMENT', 'recommended' => true],
                ['code' => 'REPLACE_ONLY', 'recommended' => false],
            ],
            'KLAIM_DISTRIBUSI_BARANG_HILANG' => [
                ['code' => 'REPLACE_ONLY', 'recommended' => true],
            ],
            'KLAIM_DISTRIBUSI_SALAH_ORDER' => [
                ['code' => 'RETURN_AND_REPLACE', 'recommended' => true],
                ['code' => 'REPLACE_ONLY', 'recommended' => false],
            ],
            // Barang Reject: kirim barang pengganti tanpa pengembalian
            // (asumsi bisnis — konfirmasi bila kebijakannya lain).
            'KLAIM_DISTRIBUSI_BARANG_REJECT' => [
                ['code' => 'REPLACE_ONLY', 'recommended' => true],
                ['code' => 'RETURN_AND_REPLACE', 'recommended' => false],
            ],
        ];

        foreach ($mapping as $categoryCode => $actionCodes) {
            $category = Category::where('code', $categoryCode)->first();
            if (! $category) continue;

            foreach ($actionCodes as $ac) {
                $action = Action::where('code', $ac['code'])->first();
                if (! $action) continue;

                \DB::table('category_actions')->updateOrInsert(
                    ['category_id' => $category->id, 'action_id' => $action->id],
                    ['is_recommended' => $ac['recommended'], 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }
}