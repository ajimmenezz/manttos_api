<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La categoría de reportería pasa a ser un catálogo gestionable
        // (catalogs type=event_status_category). Se conserva la columna string
        // 'category' por compatibilidad; la fuente de verdad pasa a category_id.
        Schema::table('event_statuses', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('category')
                ->constrained('catalogs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
    }
};
