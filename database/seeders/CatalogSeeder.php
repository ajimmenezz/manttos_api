<?php

namespace Database\Seeders;

use App\Models\Catalog;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // ── Industrias ────────────────────────────────────────────────────────
        foreach ([
            'Hotelería y Turismo', 'Retail y Comercio', 'Salud y Hospitales', 'Educación',
            'Gobierno y Sector Público', 'Manufactura e Industria', 'Tecnología',
            'Banca y Finanzas', 'Transporte y Logística', 'Bienes Raíces',
            'Entretenimiento', 'Alimentación y Restaurantes', 'Otro',
        ] as $label) {
            Catalog::firstOrCreate(['type' => 'industry', 'label' => $label], ['is_active' => true]);
        }

        // ── Tipos de sitio ────────────────────────────────────────────────────
        foreach ([
            'Hotel', 'Oficina', 'Almacén', 'Centro Comercial', 'Hospital o Clínica',
            'Edificio Corporativo', 'Planta Industrial', 'Instalación Gubernamental',
            'Institución Educativa', 'Restaurante', 'Residencial', 'Aeropuerto o Terminal', 'Otro',
        ] as $label) {
            Catalog::firstOrCreate(['type' => 'site_type', 'label' => $label], ['is_active' => true]);
        }
    }
}
