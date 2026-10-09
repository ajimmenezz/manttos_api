<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Almacenamiento: un «Drive» dentro de la plataforma, con Google Drive como motor.
 *
 * Los BYTES viven en una unidad compartida de Google Workspace, a la que solo entra
 * una cuenta de servicio. Aquí vive todo lo demás: carpetas, quién subió qué, quién
 * puede ver qué y la bitácora. Así no se paga una licencia por usuario y ningún
 * archivo queda expuesto con un enlace: Google no conoce a nuestros usuarios.
 *
 * - `storage_folders`: el árbol. `kind` = system (raíces fijas en Drive: Clientes,
 *   Libres, Papelera) · client · site (una por cliente/sitio, se crean al primer uso)
 *   · free (raíz de una carpeta libre) · folder (subcarpeta de cualquiera).
 *   `client_id`/`site_id` se copian a toda la descendencia: el alcance por rol se
 *   resuelve con una comparación, sin subir por el árbol. `path` (`/1/5/9/`) es el
 *   camino materializado: los ancestros y el subárbol salen con un LIKE.
 * - `storage_files`: un renglón por archivo. `status` pending → ready: el archivo
 *   sube DIRECTO del navegador/teléfono a Google con una sesión que abrimos nosotros,
 *   y queda pendiente hasta que se confirma contra Drive.
 * - `storage_shares`: compartir una carpeta o archivo con un usuario o un rol, en tres
 *   niveles (view < upload < manage). Se hereda hacia abajo por `path`.
 * - `storage_events`: la bitácora. En Drive todo aparece como la cuenta de servicio;
 *   el autor real queda aquí.
 *
 * Papelera: no se borra nada. Mandar a la papelera mueve el archivo en Drive a la
 * carpeta `_papelera` (que nadie ve desde la plataforma) y marca `trashed_at` en el
 * renglón y en toda su descendencia, con `trash_root_*` para poder restaurar el
 * grupo completo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('storage_folders');
            $table->string('kind', 10);                     // system | client | site | free | folder
            $table->string('system_key', 20)->nullable();   // clients | free | trash (solo kind=system)
            $table->string('name', 255);
            $table->string('drive_id', 100)->nullable();    // id de la carpeta en Google Drive
            $table->string('path', 1000)->default('/');     // /1/5/9/ (incluye el propio id)

            // Ancla de alcance, copiada a toda la descendencia.
            $table->foreignId('client_id')->nullable()->constrained('clients');
            $table->foreignId('site_id')->nullable()->constrained('sites');
            // Dueño de una carpeta libre (y de su descendencia).
            $table->foreignId('owner_id')->nullable()->constrained('users');

            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('trashed_at')->nullable();
            $table->foreignId('trashed_by')->nullable()->constrained('users');
            $table->string('trash_root_type', 6)->nullable(); // folder | file: qué se mandó a la papelera
            $table->unsignedBigInteger('trash_root_id')->nullable();
            $table->timestamps();

            $table->index(['parent_id', 'trashed_at']);
            $table->index('path');
            $table->index('client_id');
            $table->index('site_id');
            $table->index('owner_id');
        });

        // Una sola carpeta por cliente, por sitio y por raíz del sistema.
        DB::statement("create unique index storage_folders_client_unique on storage_folders (client_id) where kind = 'client'");
        DB::statement("create unique index storage_folders_site_unique on storage_folders (site_id) where kind = 'site'");
        DB::statement("create unique index storage_folders_system_unique on storage_folders (system_key) where kind = 'system'");

        Schema::create('storage_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_id')->constrained('storage_folders');
            $table->string('name', 255);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('drive_id', 100)->nullable();
            $table->string('status', 10)->default('pending'); // pending | ready | failed
            $table->string('md5', 32)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->text('upload_url')->nullable();           // sesión reanudable; se borra al confirmar

            $table->foreignId('client_id')->nullable()->constrained('clients');
            $table->foreignId('site_id')->nullable()->constrained('sites');
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->string('path', 1000)->default('/');       // el path de su carpeta

            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('trashed_at')->nullable();
            $table->foreignId('trashed_by')->nullable()->constrained('users');
            $table->string('trash_root_type', 6)->nullable();
            $table->unsignedBigInteger('trash_root_id')->nullable();
            $table->timestamps();

            $table->index(['folder_id', 'status', 'trashed_at']);
            $table->index('path');
            $table->index('client_id');
            $table->index('site_id');
            $table->index(['status', 'created_at']);
        });

        Schema::create('storage_shares', function (Blueprint $table) {
            $table->id();
            $table->string('item_type', 6);                 // folder | file
            $table->unsignedBigInteger('item_id');
            $table->string('grantee_type', 4);              // user | role
            $table->unsignedBigInteger('grantee_id');
            $table->string('level', 6);                     // view | upload | manage
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['item_type', 'item_id', 'grantee_type', 'grantee_id']);
            $table->index(['grantee_type', 'grantee_id']);
        });

        Schema::create('storage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);                   // uploaded, viewed, downloaded, created, renamed, moved, shared, unshared, trashed, restored
            $table->string('item_type', 6);
            $table->unsignedBigInteger('item_id');
            $table->string('item_name', 255)->nullable();   // el nombre de ESE momento
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['item_type', 'item_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_events');
        Schema::dropIfExists('storage_shares');
        Schema::dropIfExists('storage_files');
        Schema::dropIfExists('storage_folders');
    }
};
