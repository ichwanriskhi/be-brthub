<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'code' => 'KLAIM_DISTRIBUSI',
                'name' => 'Klaim Distribusi & Pengiriman',
                'children' => [
                    'SALAH_KIRIM' => 'Salah Kirim',
                    'SALAH_SO' => 'Salah Sales Order',
                    'KURANG_KIRIM' => 'Kurang Kirim',
                    'BARANG_HILANG' => 'Barang Hilang',
                    'SALAH_ORDER' => 'Salah Order',
                    'BARANG_REJECT' => 'Barang Reject',
                ],
            ],
            [
                'code' => 'PRODUK_KENDARAAN',
                'name' => 'Produk & Kendaraan',
                'children' => [
                    'PERAWATAN_BERKALA' => 'Perawatan Berkala',
                    'KERUSAKAN_PRODUK' => 'Kerusakan Produk',
                    'KENDALA_PASANG' => 'Kendala Pemasangan',
                    'SUKU_CADANG' => 'Suku Cadang',
                    'LAINNYA' => 'Lainnya',
                ],
            ],
            [
                'code' => 'SARANA_PRASARANA',
                'name' => 'Sarana Prasarana',
                'children' => [
                    'FASILITAS_KANTOR' => 'Fasilitas Kantor',
                    'GEDUNG_LINGKUNGAN' => 'Gedung & Lingkungan',
                    'PERALATAN_KERJA' => 'Peralatan Kerja',
                    'KEBERSIHAN_KEAMANAN' => 'Kebersihan & Keamanan',
                    'LAINNYA' => 'Lainnya',
                ],
            ],
        ];

        foreach ($categories as $category) {
            $parentId = $this->upsertCategory($category['code'], $category['name']);

            foreach ($category['children'] as $suffix => $childName) {
                $this->upsertCategory("{$category['code']}_{$suffix}", $childName, $parentId);
            }
        }
    }

    private function upsertCategory(string $code, string $name, ?int $parentId = null): int
    {
        DB::table('categories')->updateOrInsert(
            ['code' => $code],
            [
                'name' => $name,
                'parent_category_id' => $parentId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return (int) DB::table('categories')->where('code', $code)->value('id');
    }
}
