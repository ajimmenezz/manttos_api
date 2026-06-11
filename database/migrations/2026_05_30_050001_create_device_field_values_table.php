<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')
                  ->constrained('devices')
                  ->cascadeOnDelete();
            $table->foreignId('system_field_id')
                  ->constrained('system_fields')
                  ->cascadeOnDelete();
            $table->string('field_key', 60);        // denormalizado para queries sin join

            // Un solo campo se rellena según field_type
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();

            $table->timestamps();

            $table->unique(['device_id', 'system_field_id']);
        });

        // Índices compuestos para filtros y agrupaciones por campo + valor
        DB::statement('CREATE INDEX idx_dfv_field_text    ON device_field_values (system_field_id, value_text)');
        DB::statement('CREATE INDEX idx_dfv_field_number  ON device_field_values (system_field_id, value_number)');
        DB::statement('CREATE INDEX idx_dfv_field_date    ON device_field_values (system_field_id, value_date)');
        DB::statement('CREATE INDEX idx_dfv_field_boolean ON device_field_values (system_field_id, value_boolean)');
        // Índice por field_key para queries cross-sistema
        DB::statement('CREATE INDEX idx_dfv_key_text      ON device_field_values (field_key, value_text)');

        // Índice GIN en custom_fields para búsquedas de contenido JSONB
        DB::statement('CREATE INDEX idx_devices_custom_fields_gin ON devices USING GIN (custom_fields)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_devices_custom_fields_gin');
        Schema::dropIfExists('device_field_values');
    }
};
