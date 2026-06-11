<?php

namespace Database\Seeders;

use App\Models\Catalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Inserta el campo DID (Device ID) como primer campo de plantilla
 * en todos los sistemas activos que aún no lo tengan.
 */
class DeviceIdFieldSeeder extends Seeder
{
    public function run(): void
    {
        $systems = Catalog::where('type', 'system')->where('is_active', true)->get();

        $inserted = 0;

        foreach ($systems as $system) {
            $exists = DB::table('system_fields')
                ->where('catalog_id', $system->id)
                ->where('field_key', 'did')
                ->exists();

            if ($exists) continue;

            // Empujar todos los campos existentes un sort_order hacia abajo
            DB::table('system_fields')
                ->where('catalog_id', $system->id)
                ->increment('sort_order');

            DB::table('system_fields')->insert([
                'catalog_id'   => $system->id,
                'label'        => 'DID',
                'field_key'    => 'did',
                'field_type'   => 'text',
                'catalog_type' => null,
                'is_required'  => false,
                'max_length'   => 100,
                'sort_order'   => 0,
                'is_active'    => true,
                'created_by'   => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $inserted++;
        }

        $this->command->info("DID insertado en {$inserted} sistema(s). " . ($systems->count() - $inserted) . " ya lo tenían.");
    }
}
