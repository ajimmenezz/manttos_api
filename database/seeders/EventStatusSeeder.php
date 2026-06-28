<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EventStatus;
use Illuminate\Support\Facades\DB;

class EventStatusSeeder extends Seeder
{
    public function run(): void
    {
        // Estados generales por defecto (ITIL-simplificado). Idempotente por 'key'.
        $statuses = [
            ['key' => 'pendiente_captura', 'label' => 'Pendiente de captura', 'color' => '#D97706', 'category' => 'abierto',  'is_initial' => true,  'is_terminal' => false, 'sort_order' => 1],
            ['key' => 'en_progreso',       'label' => 'En progreso',          'color' => '#2563EB', 'category' => 'abierto',  'is_initial' => true,  'is_terminal' => false, 'sort_order' => 2],
            ['key' => 'en_espera',         'label' => 'En espera',            'color' => '#6B7280', 'category' => 'abierto',  'is_initial' => false, 'is_terminal' => false, 'sort_order' => 3],
            ['key' => 'resuelto',          'label' => 'Resuelto',             'color' => '#16A34A', 'category' => 'resuelto', 'is_initial' => false, 'is_terminal' => false, 'sort_order' => 4],
            ['key' => 'cerrado',           'label' => 'Cerrado',              'color' => '#334155', 'category' => 'cerrado',  'is_initial' => false, 'is_terminal' => true,  'sort_order' => 5],
            ['key' => 'cancelado',         'label' => 'Cancelado',            'color' => '#DC2626', 'category' => 'cerrado',  'is_initial' => false, 'is_terminal' => true,  'sort_order' => 6],
        ];

        foreach ($statuses as $s) {
            EventStatus::firstOrCreate(['key' => $s['key']], $s);
        }

        // Transiciones GENERALES permitidas (flujo por defecto).
        $byKey = EventStatus::pluck('id', 'key');
        $transitions = [
            ['pendiente_captura', 'en_progreso'],
            ['pendiente_captura', 'cancelado'],
            ['en_progreso', 'en_espera'],
            ['en_progreso', 'resuelto'],
            ['en_progreso', 'cancelado'],
            ['en_espera', 'en_progreso'],
            ['en_espera', 'resuelto'],
            ['en_espera', 'cancelado'],
            ['resuelto', 'cerrado'],
            ['resuelto', 'en_progreso'], // reabrir
        ];

        foreach ($transitions as [$from, $to]) {
            if (! isset($byKey[$from], $byKey[$to])) {
                continue;
            }
            DB::table('event_status_transitions')->updateOrInsert(
                ['from_status_id' => $byKey[$from], 'to_status_id' => $byKey[$to]],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
