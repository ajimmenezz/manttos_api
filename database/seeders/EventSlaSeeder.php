<?php

namespace Database\Seeders;

use App\Models\EventSlaSetting;
use App\Models\EventSlaTier;
use App\Support\EventSla;
use Illuminate\Database\Seeder;

class EventSlaSeeder extends Seeder
{
    public function run(): void
    {
        // Niveles de atención (Remota N1 → En sitio → Especializada). Idempotente.
        $tiers = [
            ['key' => 'remota_n1',     'label' => 'Atención Remota (Nivel 1)', 'sort_order' => 1],
            ['key' => 'en_sitio',      'label' => 'Atención en sitio',          'sort_order' => 2],
            ['key' => 'especializada', 'label' => 'Atención especializada',     'sort_order' => 3],
        ];
        foreach ($tiers as $t) {
            EventSlaTier::firstOrCreate(['key' => $t['key']], [
                'label' => $t['label'], 'sort_order' => $t['sort_order'], 'is_active' => true,
            ]);
        }

        // Configuración global por defecto (matriz + objetivos + calendario). Idempotente:
        // solo se crea si no existe la fila global (client_id NULL).
        $d = EventSla::defaults();
        EventSlaSetting::firstOrCreate(['client_id' => null], [
            'enabled'    => $d['enabled'],
            'matrix'     => $d['matrix'],
            'priorities' => $d['priorities'],
            'calendar'   => $d['calendar'],
        ]);
    }
}
