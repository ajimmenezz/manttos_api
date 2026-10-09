<?php

namespace App\Services\Storage;

use App\Models\StorageFile;
use App\Models\StorageFolder;
use App\Models\StorageShare;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quién puede hacer qué en Almacenamiento. Se crea POR USUARIO y por petición
 * (`StorageAccess::for($user)`): memoriza sus asignaciones, y por eso no se inyecta
 * en el controlador, que sobrevive entre peticiones.
 *
 * Un usuario llega a una carpeta o archivo por uno de tres caminos:
 *
 * 1. ALCANCE de su rol, el mismo de todo el sistema: superadmin/admin todo; admin-cliente
 *    lo anclado a SUS clientes (incluidos sus sitios); admin-sitio lo anclado a SUS sitios
 *    (no la carpeta del cliente). Ingenieros y técnicos no tienen alcance aquí: los
 *    sitios pueden guardar contratos que no les toca ver; entran solo si se les comparte.
 *    Qué puede HACER por este camino lo dicen sus permisos (`storage.upload`, `.manage`,
 *    `.share`), como en el resto de la plataforma.
 * 2. DUEÑO de una carpeta libre: todo sobre ella y lo que contenga.
 * 3. COMPARTIDO con él o con uno de sus roles, en la carpeta o en un ancestro. Aquí el
 *    nivel compartido ES la capacidad: compartir «Ver y subir» deja subir aunque el rol
 *    no traiga `storage.upload`.
 *
 * Todo exige además `storage.view` (entrar al módulo). La papelera es aparte:
 * `storage.archive`, solo superadmin por omisión.
 */
class StorageAccess
{
    private ?bool $full = null;
    private ?array $clientIds = null;
    private ?array $siteIds = null;
    private ?array $roleIds = null;
    /** @var array<string,int> */
    private array $shareCache = [];

    private function __construct(public readonly User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    // ── Asignaciones ────────────────────────────────────────────────

    public function isFull(): bool
    {
        return $this->full ??= $this->user->hasAnyRole(['superadmin', 'admin']);
    }

    /** Clientes completos (admin-cliente). Para superadmin/admin no se usa: ven todo. */
    public function clientIds(): array
    {
        if ($this->clientIds !== null) return $this->clientIds;
        return $this->clientIds = $this->user->hasRole('admin-cliente')
            ? $this->user->clientsAsAdmin()->pluck('clients.id')->map(fn ($v) => (int) $v)->all()
            : [];
    }

    /** Sitios sueltos (admin-sitio). */
    public function siteIds(): array
    {
        if ($this->siteIds !== null) return $this->siteIds;
        return $this->siteIds = $this->user->hasRole('admin-sitio')
            ? $this->user->sitesAsAdmin()->pluck('sites.id')->map(fn ($v) => (int) $v)->all()
            : [];
    }

    public function roleIds(): array
    {
        return $this->roleIds ??= $this->user->roles()->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function canEnter(): bool
    {
        return $this->user->can('storage.view');
    }

    public function canAccessClient(int $clientId): bool
    {
        return $this->isFull() || in_array($clientId, $this->clientIds(), true);
    }

    public function canAccessSite(int $siteId, int $clientId): bool
    {
        return $this->canAccessClient($clientId) || in_array($siteId, $this->siteIds(), true);
    }

    // ── Nivel sobre un elemento ─────────────────────────────────────

    /** ¿Llega por su rol o por ser dueño? (camino 1 o 2) */
    private function byScope(StorageFolder|StorageFile $item): bool
    {
        if ($item instanceof StorageFolder && $item->kind === 'system') return $this->isFull();
        if ($this->isFull()) return true;
        if ($item->client_id && in_array((int) $item->client_id, $this->clientIds(), true)) return true;
        if ($item->site_id && in_array((int) $item->site_id, $this->siteIds(), true)) return true;
        return false;
    }

    private function isOwner(StorageFolder|StorageFile $item): bool
    {
        return $item->owner_id && (int) $item->owner_id === (int) $this->user->id;
    }

    /** Nivel compartido más alto (0 = nada) sobre el elemento o cualquier ancestro. */
    public function shareLevel(StorageFolder|StorageFile $item): int
    {
        $key = ($item instanceof StorageFile ? 'file:' : 'folder:') . $item->id;
        if (isset($this->shareCache[$key])) return $this->shareCache[$key];

        $folderIds = array_map('intval', array_filter(explode('/', $item->path)));

        $level = StorageShare::query()
            ->where(function ($q) use ($item, $folderIds) {
                $q->where(fn ($q) => $q->where('item_type', 'folder')->whereIn('item_id', $folderIds ?: [0]));
                if ($item instanceof StorageFile) {
                    $q->orWhere(fn ($q) => $q->where('item_type', 'file')->where('item_id', $item->id));
                }
            })
            ->where(function ($q) {
                $q->where(fn ($q) => $q->where('grantee_type', 'user')->where('grantee_id', $this->user->id))
                  ->orWhere(fn ($q) => $q->where('grantee_type', 'role')->whereIn('grantee_id', $this->roleIds() ?: [0]));
            })
            ->pluck('level')
            ->map(fn ($l) => StorageShare::LEVELS[$l] ?? 0)
            ->max() ?? 0;

        return $this->shareCache[$key] = (int) $level;
    }

    public function canView(StorageFolder|StorageFile $item): bool
    {
        if (!$this->canEnter() || $item->trashed_at) return false;
        return $this->byScope($item) || $this->isOwner($item) || $this->shareLevel($item) >= 1;
    }

    /** Subir archivos y crear subcarpetas. */
    public function canUpload(StorageFolder|StorageFile $item): bool
    {
        if (!$this->canView($item)) return false;
        if ($this->isOwner($item) || $this->shareLevel($item) >= 2) return true;
        return $this->byScope($item) && $this->user->can('storage.upload');
    }

    /** Renombrar y mover. */
    public function canManage(StorageFolder|StorageFile $item): bool
    {
        if (!$this->canView($item)) return false;
        if ($this->isOwner($item) || $this->shareLevel($item) >= 3) return true;
        return $this->byScope($item) && $this->user->can('storage.manage');
    }

    public function canShare(StorageFolder|StorageFile $item): bool
    {
        if (!$this->canView($item)) return false;
        if ($this->isOwner($item) || $this->shareLevel($item) >= 3) return true;
        return $this->byScope($item) && $this->user->can('storage.share');
    }

    public function canTrash(): bool
    {
        return $this->user->can('storage.archive');
    }

    public function abilities(StorageFolder|StorageFile $item): array
    {
        return [
            'view'   => $this->canView($item),
            'upload' => $this->canUpload($item),
            'manage' => $this->canManage($item),
            'share'  => $this->canShare($item),
            'trash'  => $this->canTrash() && !($item instanceof StorageFolder && $item->isAnchor()),
        ];
    }

    // ── Consultas: todo lo visible ──────────────────────────────────

    /** Lo compartido con el usuario: [['item_type','item_id','level'], …]. */
    public function sharedWithMe(): array
    {
        return StorageShare::query()
            ->where(function ($q) {
                $q->where(fn ($q) => $q->where('grantee_type', 'user')->where('grantee_id', $this->user->id))
                  ->orWhere(fn ($q) => $q->where('grantee_type', 'role')->whereIn('grantee_id', $this->roleIds() ?: [0]));
            })
            ->get(['item_type', 'item_id', 'level'])
            ->all();
    }

    /**
     * Restringe una consulta de carpetas o archivos a lo que el usuario puede ver.
     * Sirve para la búsqueda: el camino «compartido» se resuelve por prefijo de `path`.
     */
    public function constrainVisible(Builder $q, string $type): Builder
    {
        $q->whereNull('trashed_at');
        if ($type === 'folder') $q->where('kind', '!=', 'system');
        if ($this->isFull()) return $q;

        $shares = collect($this->sharedWithMe());
        $folderPaths = StorageFolder::whereIn('id', $shares->where('item_type', 'folder')->pluck('item_id')->all() ?: [0])
            ->pluck('path')->all();
        $fileIds = $shares->where('item_type', 'file')->pluck('item_id')->all();

        return $q->where(function ($w) use ($folderPaths, $fileIds, $type) {
            $w->whereIn('client_id', $this->clientIds() ?: [0])
              ->orWhereIn('site_id', $this->siteIds() ?: [0])
              ->orWhere('owner_id', $this->user->id);
            foreach ($folderPaths as $p) {
                $w->orWhere('path', 'like', $p . '%');
            }
            if ($type === 'file' && $fileIds) $w->orWhereIn('id', $fileIds);
        });
    }
}
