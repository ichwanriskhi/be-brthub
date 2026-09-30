<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Lini Produk.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            'ECU' => 'ECU',
            'CDI' => 'CDI',
            'KIPROK_REGULATOR' => 'Kiprok & Regulator',
            'SMARTKEY' => 'Smartkey',
            'BORE_UP_KIT' => 'Bore Up Kit',
            'CYLINDER_HEAD' => 'Cylinder Head',
            'CAM_SHAFT' => 'Cam Shaft / Noken As',
            'THROTTLE_BODY' => 'Throttle Body',
            'INJECTOR' => 'Injector',
            'CARBURETOR' => 'Carburetor',
            'VALVE' => 'Valve / Klep',
            'VALVE_SPRING' => 'Valve Spring',
            'CLUTCH' => 'Clutch',
            'CRANKSHAFT' => 'Crankshaft',
            'CVT' => 'CVT',
            'BRAKE' => 'Brake System',
            'WHEEL' => 'Wheel / Velg',
            'WORKSHOP_TOOLS' => 'Workshop Tools',
            'EV_CONVERSION' => 'EV Conversion',
        ];

        foreach ($products as $code => $name) {
            DB::table('products')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
