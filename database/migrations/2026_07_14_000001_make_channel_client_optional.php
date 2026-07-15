<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La línea de captación deja de estar atada a un cliente: puede atender a VARIOS.
 * Con client_id nulo, el agente identifica el cliente a partir del sitio que el
 * contacto menciona (cada sitio pertenece a un cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE channels ALTER COLUMN client_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Requiere que no haya líneas multi-cliente (client_id null) para revertir.
        DB::statement('ALTER TABLE channels ALTER COLUMN client_id SET NOT NULL');
    }
};
