<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            // Enlaza un estado con el nivel de atención de SLA que representa. Cuando un
            // evento entra (por primera vez) a un estado con este nivel, se considera que
            // esa atención se dio y se mide contra su objetivo. null = el estado no marca
            // ningún nivel de atención.
            $table->foreignId('sla_tier_id')->nullable()->after('requires_note')
                ->constrained('event_sla_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sla_tier_id');
        });
    }
};
