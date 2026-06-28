<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Catalog;
use App\Models\EventStatus;

class EventStatusCategorySeeder extends Seeder
{
    public function run(): void
    {
        // Categorías de reportería por defecto, como catálogo gestionable.
        $defaults = ['Abierto', 'Resuelto', 'Cerrado'];
        $ids = [];
        foreach ($defaults as $i => $label) {
            $cat = Catalog::firstOrCreate(
                ['type' => Catalog::TYPE_EVENT_STATUS_CATEGORY, 'label' => $label],
                ['sort_order' => $i, 'is_active' => true],
            );
            $ids[mb_strtolower($label)] = $cat->id;
        }

        // Backfill: enlaza los estados existentes a su categoría por el string viejo.
        foreach (EventStatus::whereNull('category_id')->get() as $status) {
            $key = mb_strtolower((string) $status->category);
            if (isset($ids[$key])) {
                $status->update(['category_id' => $ids[$key]]);
            }
        }
    }
}
