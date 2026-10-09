<?php

namespace App\Services\Storage;

use App\Models\Client;
use App\Models\Site;
use App\Models\StorageEvent;
use App\Models\StorageFile;
use App\Models\StorageFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mantiene el árbol de Almacenamiento y su espejo en Google Drive.
 *
 * En Drive la unidad queda con la MISMA forma que se ve en la plataforma:
 *
 *   Clientes/<cliente>/<sitio>/…   Libres/<carpeta>/…   _papelera/…
 *
 * No es necesario para que funcione —los permisos y los nombres viven aquí—, pero si
 * algún día faltara esta base, la unidad seguiría siendo navegable por una persona.
 *
 * Orden en cada operación: primero Drive y después la base. Si Drive falla no se toca
 * nada; si falla la base tras mover en Drive, el archivo queda donde Drive dice y una
 * nueva operación lo corrige (es idempotente). Al revés quedarían renglones que
 * apuntan a algo que no existe.
 */
class StorageTree
{
    private const SYSTEM_NAMES = ['clients' => 'Clientes', 'free' => 'Libres', 'trash' => '_papelera'];

    public function __construct(private GoogleDrive $drive) {}

    // ── Carpetas que se crean solas ─────────────────────────────────

    public function systemRoot(string $key): StorageFolder
    {
        return $this->once("system:{$key}", fn () => StorageFolder::where('kind', 'system')->where('system_key', $key)->first(),
            function () use ($key) {
                $driveId = $this->drive->createFolder(self::SYSTEM_NAMES[$key], $this->drive->rootId(), ['mtto_system' => $key]);
                return $this->insertFolder(['kind' => 'system', 'system_key' => $key, 'name' => self::SYSTEM_NAMES[$key], 'drive_id' => $driveId], null);
            });
    }

    public function clientFolder(Client $client): StorageFolder
    {
        return $this->once("client:{$client->id}", fn () => StorageFolder::where('kind', 'client')->where('client_id', $client->id)->first(),
            function () use ($client) {
                $parent = $this->systemRoot('clients');
                $driveId = $this->drive->createFolder($client->name, $parent->drive_id, ['mtto_client_id' => (string) $client->id]);
                return $this->insertFolder([
                    'kind' => 'client', 'name' => $client->name, 'drive_id' => $driveId, 'client_id' => $client->id,
                ], $parent);
            });
    }

    public function siteFolder(Site $site): StorageFolder
    {
        return $this->once("site:{$site->id}", fn () => StorageFolder::where('kind', 'site')->where('site_id', $site->id)->first(),
            function () use ($site) {
                $parent = $this->clientFolder($site->client()->withTrashed()->first());
                $driveId = $this->drive->createFolder($site->name, $parent->drive_id, ['mtto_site_id' => (string) $site->id]);
                return $this->insertFolder([
                    'kind' => 'site', 'name' => $site->name, 'drive_id' => $driveId,
                    'client_id' => $site->client_id, 'site_id' => $site->id,
                ], $parent);
            });
    }

    /**
     * Busca o crea bajo un candado de Postgres: dos personas que abren por primera vez
     * el mismo sitio a la vez crearían dos carpetas en Drive (el índice único frena el
     * renglón, pero no la carpeta huérfana).
     */
    private function once(string $lockKey, callable $find, callable $create): StorageFolder
    {
        if ($found = $find()) return $found;

        return DB::transaction(function () use ($lockKey, $find, $create) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['storage:' . $lockKey]);
            return $find() ?? $create();
        });
    }

    private function insertFolder(array $attrs, ?StorageFolder $parent): StorageFolder
    {
        $folder = StorageFolder::create($attrs + ['parent_id' => $parent?->id, 'path' => '/']);
        $folder->path = ($parent?->path ?? '/') . $folder->id . '/';
        $folder->save();
        return $folder;
    }

    // ── Operaciones del usuario ─────────────────────────────────────

    /** `$parent` null = carpeta libre nueva, del usuario. */
    public function createFolder(?StorageFolder $parent, string $name, User $user): StorageFolder
    {
        $driveParent = $parent ?? $this->systemRoot('free');
        $driveId = $this->drive->createFolder($name, $driveParent->drive_id);

        return $this->insertFolder([
            'kind' => $parent ? 'folder' : 'free',
            'name' => $name,
            'drive_id' => $driveId,
            'client_id' => $parent?->client_id,
            'site_id' => $parent?->site_id,
            'owner_id' => $parent ? $parent->owner_id : $user->id,
            'created_by' => $user->id,
        ], $driveParent);
    }

    public function rename(StorageFolder|StorageFile $item, string $name): void
    {
        if ($item->drive_id) $this->drive->rename($item->drive_id, $name);
        $item->update(['name' => $name]);
    }

    public function move(StorageFolder|StorageFile $item, StorageFolder $dest): void
    {
        if ($dest->trashed_at || $dest->kind === 'system') {
            throw ValidationException::withMessages(['destination' => 'Ese destino no está disponible.']);
        }
        if ($item instanceof StorageFolder) {
            if ($item->isAnchor()) throw ValidationException::withMessages(['folder' => 'Las carpetas de cliente y sitio no se mueven.']);
            if (str_starts_with($dest->path, $item->path)) {
                throw ValidationException::withMessages(['destination' => 'No se puede mover una carpeta dentro de sí misma.']);
            }
            if ((int) $item->parent_id === (int) $dest->id) return;
        } elseif ((int) $item->folder_id === (int) $dest->id) {
            return;
        }

        $this->drive->move($item->drive_id, $dest->drive_id);

        DB::transaction(function () use ($item, $dest) {
            $anchor = ['client_id' => $dest->client_id, 'site_id' => $dest->site_id, 'owner_id' => $dest->owner_id];

            if ($item instanceof StorageFile) {
                $item->update(['folder_id' => $dest->id, 'path' => $dest->path] + $anchor);
                return;
            }

            $old = $item->path;
            $new = $dest->path . $item->id . '/';
            $item->update(['parent_id' => $dest->id, 'kind' => 'folder'] + $anchor);

            // El subárbol entero: camino nuevo y ancla del destino.
            foreach (['storage_folders', 'storage_files'] as $table) {
                DB::table($table)->where('path', 'like', $old . '%')->update($anchor);
                DB::update("update {$table} set path = ? || substr(path, ?) where path like ?", [$new, strlen($old) + 1, $old . '%']);
            }
        });
    }

    public function trash(StorageFolder|StorageFile $item, User $user): void
    {
        if ($item instanceof StorageFolder && $item->isAnchor()) {
            throw ValidationException::withMessages(['folder' => 'Las carpetas de cliente y sitio no se mandan a la papelera.']);
        }
        if ($item->drive_id) $this->drive->move($item->drive_id, $this->systemRoot('trash')->drive_id);

        $type = $item instanceof StorageFile ? 'file' : 'folder';
        $mark = ['trashed_at' => now(), 'trashed_by' => $user->id, 'trash_root_type' => $type, 'trash_root_id' => $item->id];

        DB::transaction(function () use ($item, $type, $mark) {
            if ($type === 'file') {
                $item->update($mark);
                return;
            }
            // La carpeta y lo que contenga que aún estuviera vivo. Lo que ya estaba en la
            // papelera por su cuenta conserva su propio `trash_root` y se restaura aparte.
            StorageFolder::where('path', 'like', $item->path . '%')->whereNull('trashed_at')->update($mark);
            StorageFile::where('path', 'like', $item->path . '%')->whereNull('trashed_at')->update($mark);
        });
    }

    public function restore(StorageFolder|StorageFile $item): void
    {
        $type = $item instanceof StorageFile ? 'file' : 'folder';
        if ($item->trash_root_type !== $type || (int) $item->trash_root_id !== (int) $item->id) {
            throw ValidationException::withMessages(['item' => 'Esto se fue a la papelera junto con su carpeta: restaura la carpeta.']);
        }

        $parent = $type === 'file' ? $item->folder : $item->parent;
        if (!$parent || $parent->trashed_at) {
            throw ValidationException::withMessages(['item' => "Su carpeta original («{$parent?->name}») también está en la papelera: restáurala primero."]);
        }

        if ($item->drive_id) $this->drive->move($item->drive_id, $parent->drive_id);

        $clear = ['trashed_at' => null, 'trashed_by' => null, 'trash_root_type' => null, 'trash_root_id' => null];
        foreach ([StorageFolder::class, StorageFile::class] as $model) {
            $model::where('trash_root_type', $type)->where('trash_root_id', $item->id)->update($clear);
        }
    }

    /** Las carpetas de cliente y sitio siguen el nombre de su entidad. Nunca rompe el guardado. */
    public function syncAnchorName(string $kind, int $id, string $name): void
    {
        try {
            $folder = StorageFolder::where('kind', $kind)->where($kind . '_id', $id)->first();
            if (!$folder || $folder->name === $name) return;
            $this->rename($folder, $name);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ── Bitácora ────────────────────────────────────────────────────

    public static function log(?User $user, string $action, StorageFolder|StorageFile $item, array $meta = []): void
    {
        try {
            $req = request();
            StorageEvent::create([
                'user_id' => $user?->id,
                'action' => $action,
                'item_type' => $item instanceof StorageFile ? 'file' : 'folder',
                'item_id' => $item->id,
                'item_name' => $item->name,
                'meta' => $meta ?: null,
                'ip' => $req?->ip(),
                'user_agent' => mb_substr((string) $req?->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e); // la bitácora nunca tumba la operación
        }
    }
}
