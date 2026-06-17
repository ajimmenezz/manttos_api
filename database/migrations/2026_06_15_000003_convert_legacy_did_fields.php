<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Absorbe el campo especial `did` (sembrado como texto) en el nuevo tipo de campo
     * 'did' en modo texto, para que los filtros lo detecten por field_type.
     */
    public function up(): void
    {
        DB::table('system_fields')
            ->where('field_key', 'did')
            ->where('field_type', 'text')
            ->update([
                'field_type' => 'did',
                'config'     => json_encode(['did_mode' => 'text']),
            ]);
    }

    public function down(): void
    {
        DB::table('system_fields')
            ->where('field_key', 'did')
            ->where('field_type', 'did')
            ->update([
                'field_type' => 'text',
                'config'     => null,
            ]);
    }
};
