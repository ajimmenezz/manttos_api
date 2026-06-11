<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Valores por defecto
        DB::table('app_settings')->insert([
            ['key' => 'app_name',      'value' => 'TechMaint',  'updated_at' => now()],
            ['key' => 'logo_url',      'value' => null,          'updated_at' => now()],
            ['key' => 'login_bg_url',  'value' => null,          'updated_at' => now()],
            ['key' => 'color_preset',  'value' => 'blue',        'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
