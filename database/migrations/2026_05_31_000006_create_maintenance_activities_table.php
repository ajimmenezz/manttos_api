<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('maintenance_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->jsonb('field_values')->default('{}');
            $table->timestamp('performed_at')->useCurrent();
            $table->timestamps();

            $table->index(['maintenance_id', 'device_id']);
            $table->index(['maintenance_id', 'activity_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_activities');
    }
};
