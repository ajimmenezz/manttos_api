<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use App\Models\StorageEvent;
use App\Models\StorageFile;
use App\Models\StorageFolder;
use App\Models\StorageShare;
use App\Models\User;
use App\Services\Storage\GoogleDrive;
use App\Services\Storage\StorageAccess;
use App\Services\Storage\StorageTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo Almacenamiento: carpetas y archivos guardados en Google Drive, con los permisos
 * y la bitácora de la plataforma. Quién puede qué: ver `StorageAccess`. Cómo se refleja
 * en Drive: ver `StorageTree`.
 *
 * Subida en tres pasos, sin que el archivo pase por aquí:
 *   1. `POST folders/{f}/uploads` → abrimos la sesión en Drive y devolvemos su URL.
 *   2. El navegador/teléfono hace `PUT` con los bytes directo a Google.
 *   3. `POST files/{f}/complete` → lo confirmamos contra Drive y queda listo.
 * Si el paso 3 nunca llega, `storage:reconcile` lo encuentra por su `appProperties`.
 */
class StorageController extends Controller
{
    public function __construct(private StorageTree $tree, private GoogleDrive $drive) {}

    private function access(Request $request): StorageAccess
    {
        $access = StorageAccess::for($request->user());
        abort_unless($access->canEnter(), 403, 'No tienes acceso a Almacenamiento.');
        abort_unless($this->drive->configured(), 503, 'Almacenamiento no está configurado en este servidor (falta la unidad de Google Drive).');
        return $access;
    }

    private function item(string $type, int $id): StorageFolder|StorageFile
    {
        abort_unless(in_array($type, ['folder', 'file'], true), 404);
        return $type === 'folder' ? StorageFolder::findOrFail($id) : StorageFile::findOrFail($id);
    }

    // ── Inicio ──────────────────────────────────────────────────────

    /**
     * Lo que alimenta la sección «Almacenamiento»: clientes y sitios del alcance, mis
     * carpetas libres y lo compartido conmigo.
     */
    public function home(Request $request): JsonResponse
    {
        $access = $this->access($request);
        $user = $request->user();

        // Clientes y sitios a los que llega por su rol.
        $clientQuery = Client::query()->orderBy('name');
        $siteQuery = Site::query()->orderBy('name');
        if (!$access->isFull()) {
            $clientIds = array_unique(array_merge(
                $access->clientIds(),
                Site::whereIn('id', $access->siteIds() ?: [0])->pluck('client_id')->all(),
            ));
            $clientQuery->whereIn('id', $clientIds ?: [0]);
            $siteQuery->where(fn ($q) => $q->whereIn('client_id', $access->clientIds() ?: [0])->orWhereIn('id', $access->siteIds() ?: [0]));
        }
        $sites = $siteQuery->get(['id', 'client_id', 'name'])->groupBy('client_id');
        $folders = StorageFolder::whereIn('kind', ['client', 'site'])->get(['id', 'kind', 'client_id', 'site_id']);

        $clients = $clientQuery->get(['id', 'name'])->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'can_open' => $access->canAccessClient($c->id),
            'folder_id' => $folders->firstWhere(fn ($f) => $f->kind === 'client' && $f->client_id === $c->id)?->id,
            'sites' => ($sites[$c->id] ?? collect())->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'folder_id' => $folders->firstWhere(fn ($f) => $f->kind === 'site' && $f->site_id === $s->id)?->id,
            ])->values(),
        ])->values();

        $free = StorageFolder::where('kind', 'free')->whereNull('trashed_at')
            ->when(!$access->isFull(), fn ($q) => $q->where('owner_id', $user->id))
            ->with('owner:id,name')->orderBy('name')->get()
            ->map(fn ($f) => $this->folderRow($f) + ['owner' => $f->owner?->only('id', 'name'), 'mine' => $f->owner_id === $user->id])
            ->values();

        return response()->json([
            'clients' => $clients,
            'free' => $free,
            'shared' => $this->sharedRows($access),
            'can' => [
                'create_free' => $user->can('storage.create-free-folders'),
                'trash' => $access->canTrash(),
            ],
            'max_upload_mb' => config('services.google_drive.max_upload_mb'),
        ]);
    }

    /** Lo compartido conmigo, sin repetir lo que ya viene dentro de otra carpeta compartida. */
    private function sharedRows(StorageAccess $access): array
    {
        $shares = collect($access->sharedWithMe());
        $levels = $shares->groupBy(fn ($s) => $s->item_type . ':' . $s->item_id)
            ->map(fn ($g) => $g->max(fn ($s) => StorageShare::LEVELS[$s->level] ?? 0));

        $folders = StorageFolder::whereIn('id', $shares->where('item_type', 'folder')->pluck('item_id')->all() ?: [0])
            ->whereNull('trashed_at')->get();
        $files = StorageFile::whereIn('id', $shares->where('item_type', 'file')->pluck('item_id')->all() ?: [0])
            ->whereNull('trashed_at')->ready()->with('uploader:id,name')->get();
        $sharedFolderIds = $folders->pluck('id')->all();

        $isInside = fn (string $path, int $selfId) => (bool) array_intersect(
            array_diff(array_map('intval', array_filter(explode('/', $path))), [$selfId]),
            $sharedFolderIds,
        );
        $label = fn (int $l) => array_search($l, StorageShare::LEVELS, true) ?: 'view';

        $rows = [];
        foreach ($folders as $f) {
            if ($isInside($f->path, $f->id)) continue;
            $rows[] = ['type' => 'folder'] + $this->folderRow($f) + ['level' => $label($levels['folder:' . $f->id])];
        }
        foreach ($files as $f) {
            if ($isInside($f->path, 0)) continue;
            $rows[] = ['type' => 'file'] + $this->fileRow($f, $access->user) + ['level' => $label($levels['file:' . $f->id])];
        }
        return $rows;
    }

    // ── Carpetas ancladas (se crean al primer uso) ──────────────────

    public function clientFolder(Request $request, Client $client): JsonResponse
    {
        $access = $this->access($request);
        abort_unless($access->canAccessClient($client->id), 403, 'No tienes acceso a los documentos de este cliente.');
        return $this->showFolder($request, $this->tree->clientFolder($client), $access);
    }

    public function siteFolder(Request $request, Site $site): JsonResponse
    {
        $access = $this->access($request);
        abort_unless($access->canAccessSite($site->id, $site->client_id), 403, 'No tienes acceso a los documentos de este sitio.');
        return $this->showFolder($request, $this->tree->siteFolder($site), $access);
    }

    // ── Carpetas ────────────────────────────────────────────────────

    public function folder(Request $request, StorageFolder $folder): JsonResponse
    {
        return $this->showFolder($request, $folder, $this->access($request));
    }

    private function showFolder(Request $request, StorageFolder $folder, StorageAccess $access): JsonResponse
    {
        abort_unless($access->canView($folder), 403, 'No tienes acceso a esta carpeta.');

        // Migas: solo los ancestros que el usuario puede abrir, sin las raíces del sistema.
        $ancestors = StorageFolder::whereIn('id', $folder->ancestorIds() ?: [0])->where('kind', '!=', 'system')->get()->keyBy('id');
        $crumbs = [];
        foreach ($folder->ancestorIds() as $id) {
            $a = $ancestors[$id] ?? null;
            if ($a && $access->canView($a)) $crumbs[] = ['id' => $a->id, 'name' => $this->displayName($a), 'kind' => $a->kind];
        }

        $children = $folder->children()->whereNull('trashed_at')->orderByRaw("case kind when 'site' then 0 else 1 end")->orderBy('name')->get();
        $files = $folder->files()->whereNull('trashed_at')->ready()->with('uploader:id,name')->orderBy('name')->get();

        return response()->json([
            'folder' => $this->folderRow($folder) + [
                'client' => $folder->client_id ? $folder->client?->only('id', 'name') : null,
                'site' => $folder->site_id ? $folder->site?->only('id', 'name') : null,
                'can' => $access->abilities($folder),
                'shared' => StorageShare::where('item_type', 'folder')->where('item_id', $folder->id)->exists(),
            ],
            'breadcrumbs' => $crumbs,
            'folders' => $children->map(fn ($f) => $this->folderRow($f))->values(),
            'files' => $files->map(fn ($f) => $this->fileRow($f, $request->user()))->values(),
        ]);
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $access = $this->access($request);
        $data = $request->validate([
            'parent_id' => 'nullable|integer|exists:storage_folders,id',
            'name' => 'required|string|max:200',
        ]);
        $name = $this->cleanName($data['name']);

        if (empty($data['parent_id'])) {
            abort_unless($request->user()->can('storage.create-free-folders'), 403, 'No tienes permiso para crear carpetas libres.');
            $parent = null;
        } else {
            $parent = StorageFolder::findOrFail($data['parent_id']);
            abort_unless($access->canUpload($parent), 403, 'No puedes crear carpetas aquí.');
        }

        $folder = $this->tree->createFolder($parent, $name, $request->user());
        StorageTree::log($request->user(), 'created', $folder, ['parent_id' => $parent?->id]);

        return response()->json($this->folderRow($folder), 201);
    }

    // ── Renombrar / mover (carpetas y archivos) ─────────────────────

    public function rename(Request $request, string $type, int $id): JsonResponse
    {
        $access = $this->access($request);
        $item = $this->item($type, $id);
        abort_unless($access->canManage($item), 403, 'No puedes renombrar esto.');
        abort_if($item instanceof StorageFolder && $item->isAnchor(), 422, 'Las carpetas de cliente y sitio llevan el nombre de su cliente o sitio.');

        $name = $this->cleanName($request->validate(['name' => 'required|string|max:200'])['name']);
        $old = $item->name;
        $this->tree->rename($item, $name);
        StorageTree::log($request->user(), 'renamed', $item, ['from' => $old, 'to' => $name]);

        return response()->json(['ok' => true, 'name' => $name]);
    }

    public function move(Request $request, string $type, int $id): JsonResponse
    {
        $access = $this->access($request);
        $item = $this->item($type, $id);
        $dest = StorageFolder::findOrFail($request->validate(['destination_id' => 'required|integer'])['destination_id']);

        abort_unless($access->canManage($item), 403, 'No puedes mover esto.');
        abort_unless($access->canUpload($dest), 403, 'No puedes mover nada a esa carpeta.');

        $from = $item instanceof StorageFile ? $item->folder_id : $item->parent_id;
        $this->tree->move($item, $dest);
        StorageTree::log($request->user(), 'moved', $item, ['from_folder_id' => $from, 'to_folder_id' => $dest->id, 'to' => $this->displayName($dest)]);

        return response()->json(['ok' => true]);
    }

    /** Carpetas a las que el usuario puede mover cosas (para el selector de destino). */
    public function destinations(Request $request): JsonResponse
    {
        $access = $this->access($request);
        $q = trim((string) $request->query('q', ''));

        $folders = $access->constrainVisible(StorageFolder::query(), 'folder')
            ->when($q !== '', fn ($w) => $w->where('name', 'ilike', '%' . $q . '%'))
            ->orderBy('path')->limit(300)->get()
            ->filter(fn ($f) => $access->canUpload($f))
            ->take(100);

        // El camino legible de cada una, en una sola consulta.
        $ids = $folders->flatMap(fn ($f) => $f->ancestorIds())->unique()->all();
        $names = StorageFolder::whereIn('id', $ids ?: [0])->where('kind', '!=', 'system')->get()->mapWithKeys(fn ($f) => [$f->id => $this->displayName($f)]);

        return response()->json($folders->map(fn ($f) => [
            'id' => $f->id,
            'name' => $this->displayName($f),
            'kind' => $f->kind,
            'path' => collect($f->ancestorIds())->map(fn ($i) => $names[$i] ?? null)->filter()->push($this->displayName($f))->implode(' / '),
        ])->values());
    }

    // ── Subida directa a Google ─────────────────────────────────────

    public function startUpload(Request $request, StorageFolder $folder): JsonResponse
    {
        $access = $this->access($request);
        abort_unless($access->canUpload($folder), 403, 'No puedes subir archivos a esta carpeta.');

        $maxBytes = (int) config('services.google_drive.max_upload_mb') * 1024 * 1024;
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'size' => "required|integer|min:0|max:{$maxBytes}",
            'mime_type' => 'nullable|string|max:150',
        ], ['size.max' => 'El archivo pasa del máximo permitido (' . config('services.google_drive.max_upload_mb') . ' MB).']);

        $user = $request->user();
        $file = StorageFile::create([
            'folder_id' => $folder->id,
            'name' => $this->cleanName($data['name']),
            'mime_type' => $data['mime_type'] ?: 'application/octet-stream',
            'size' => $data['size'],
            'status' => 'pending',
            'client_id' => $folder->client_id,
            'site_id' => $folder->site_id,
            'owner_id' => $folder->owner_id,
            'path' => $folder->path,
            'uploaded_by' => $user->id,
        ]);

        try {
            $url = $this->drive->startResumableUpload([
                'name' => $file->name,
                'parents' => [$folder->drive_id],
                // Lo que verá quien abra la unidad directamente: el autor real.
                'description' => sprintf('Subido por %s (%s) vía Mantenimientos · %s', $user->name, $user->email, now()->timezone('America/Mexico_City')->format('Y-m-d H:i')),
                'appProperties' => ['mtto_file_id' => (string) $file->id, 'mtto_user_id' => (string) $user->id, 'mtto_install' => $this->drive->installTag()],
            ], $file->mime_type, (int) $data['size'], $request->header('Origin') ?: $request->input('origin'));
        } catch (\Throwable $e) {
            $file->update(['status' => 'failed']);
            throw $e;
        }

        $file->update(['upload_url' => $url]);

        return response()->json(['file' => $this->fileRow($file, $user), 'upload_url' => $url], 201);
    }

    public function completeUpload(Request $request, StorageFile $file): JsonResponse
    {
        $this->access($request);
        abort_unless((int) $file->uploaded_by === (int) $request->user()->id, 403);
        if ($file->status === 'ready') return response()->json($this->fileRow($file->load('uploader:id,name'), $request->user()));
        abort_unless($file->status === 'pending', 409, 'Esta subida ya no está activa.');

        $driveId = $request->input('drive_id');
        $remote = $driveId ? rescue(fn () => $this->drive->get($driveId), null, false) : null;
        $remote ??= $this->drive->findByAppProperty('mtto_file_id', (string) $file->id);

        abort_unless($remote
            && ($remote['appProperties']['mtto_file_id'] ?? null) === (string) $file->id
            && ($remote['appProperties']['mtto_install'] ?? null) === $this->drive->installTag(),
            409, 'Google todavía no confirma el archivo. Si la subida terminó, intenta de nuevo en unos segundos.');

        $this->markReady($file, $remote);
        StorageTree::log($request->user(), 'uploaded', $file, ['size' => $file->size, 'sha256' => $file->sha256]);

        return response()->json($this->fileRow($file->fresh('uploader:id,name'), $request->user()));
    }

    public function cancelUpload(Request $request, StorageFile $file): JsonResponse
    {
        abort_unless((int) $file->uploaded_by === (int) $request->user()->id, 403);
        if ($file->status === 'pending') $file->update(['status' => 'failed', 'upload_url' => null]);
        return response()->json(['ok' => true]);
    }

    public static function markReady(StorageFile $file, array $remote): void
    {
        $file->update([
            'status' => 'ready',
            'drive_id' => $remote['id'],
            'size' => isset($remote['size']) ? (int) $remote['size'] : $file->size,
            'mime_type' => $remote['mimeType'] ?? $file->mime_type,
            'md5' => $remote['md5Checksum'] ?? null,
            'sha256' => $remote['sha256Checksum'] ?? null,
            'uploaded_at' => now(),
            'upload_url' => null,
        ]);
    }

    // ── Ver y descargar ─────────────────────────────────────────────

    /**
     * Enlace temporal firmado para ver o descargar. `<img>`, `<video>` y una pestaña nueva
     * no pueden mandar el token, así que la firma lo sustituye —ligada a ESTE usuario—, y
     * al servir se revalida su acceso (como los respaldos de `snapshots`). La consulta
     * queda en la bitácora aquí, una vez, y no en cada pedazo que pida el reproductor.
     */
    public function link(Request $request, StorageFile $file): JsonResponse
    {
        $access = $this->access($request);
        abort_unless($file->status === 'ready' && $access->canView($file), 403, 'No tienes acceso a este archivo.');

        $download = $request->boolean('download');
        StorageTree::log($request->user(), $download ? 'downloaded' : 'viewed', $file);

        return response()->json(['url' => URL::temporarySignedRoute('storage.content', now()->addMinutes(30), [
            'file' => $file->id, 'u' => $request->user()->id, 'd' => $download ? 1 : 0,
        ])]);
    }

    public function content(Request $request, StorageFile $file): StreamedResponse
    {
        $user = User::find($request->integer('u'));
        abort_unless($user && $file->status === 'ready' && StorageAccess::for($user)->canView($file), 403);

        $upstream = $this->drive->download($file->drive_id, $request->header('Range'));
        abort_if($upstream->getStatusCode() >= 400, 502, 'Google Drive no entregó el archivo.');

        // HTML, SVG y XML se ejecutarían con el dominio de la API: siempre como descarga.
        $mime = $file->mime_type ?: 'application/octet-stream';
        $risky = preg_match('#html|svg|xml|javascript#i', $mime);
        $disposition = ($request->boolean('d') || $risky) ? 'attachment' : 'inline';

        $headers = [
            'Content-Type' => $risky ? 'application/octet-stream' : $mime,
            'Content-Disposition' => $disposition . "; filename=\"" . addcslashes(\Illuminate\Support\Str::ascii($file->name), '"\\') . "\"; filename*=UTF-8''" . rawurlencode($file->name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
            'Accept-Ranges' => 'bytes',
        ];
        // Texto plano en sandbox por si acaso; NO a los PDF: Chrome se niega a dibujar un
        // PDF que llega con CSP sandbox («bloqueado por Chrome»).
        if (str_starts_with($mime, 'text/')) $headers['Content-Security-Policy'] = 'sandbox';
        foreach (['Content-Length', 'Content-Range'] as $h) {
            if ($upstream->hasHeader($h)) $headers[$h] = $upstream->getHeaderLine($h);
        }

        $body = $upstream->getBody();
        return new StreamedResponse(function () use ($body) {
            while (!$body->eof()) {
                echo $body->read(1024 * 256);
                flush();
            }
        }, $upstream->getStatusCode(), $headers);
    }

    /**
     * Miniatura (imágenes, PDF, video). La firma dura hasta el final del día siguiente y
     * cambia una vez al día: así el navegador la guarda en caché y no se pide a Google cada vez.
     */
    public function thumbnail(Request $request, StorageFile $file)
    {
        $user = User::find($request->integer('u'));
        abort_unless($user && $file->status === 'ready' && StorageAccess::for($user)->canView($file), 403);

        $thumb = rescue(fn () => $this->drive->thumbnail($file->drive_id, $request->integer('s', 400) ?: 400), null, false);
        abort_unless($thumb, 404);

        return response($thumb['body'], 200, [
            'Content-Type' => $thumb['type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ── Búsqueda ────────────────────────────────────────────────────

    public function search(Request $request): JsonResponse
    {
        $access = $this->access($request);
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) return response()->json(['folders' => [], 'files' => []]);
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $folders = $access->constrainVisible(StorageFolder::query(), 'folder')->where('name', 'ilike', $like)->orderBy('name')->limit(50)->get();
        $files = $access->constrainVisible(StorageFile::query(), 'file')->ready()->where('name', 'ilike', $like)
            ->with(['uploader:id,name', 'folder'])->orderByDesc('uploaded_at')->limit(100)->get();

        return response()->json([
            'folders' => $folders->map(fn ($f) => $this->folderRow($f))->values(),
            'files' => $files->map(fn ($f) => $this->fileRow($f, $request->user()) + [
                'folder' => ['id' => $f->folder_id, 'name' => $f->folder ? $this->displayName($f->folder) : null],
            ])->values(),
        ]);
    }

    // ── Compartir ───────────────────────────────────────────────────

    public function shares(Request $request, string $type, int $id): JsonResponse
    {
        $access = $this->access($request);
        $item = $this->item($type, $id);
        abort_unless($access->canShare($item), 403, 'No puedes compartir esto.');

        // Lo compartido directamente y lo que se hereda de las carpetas de arriba.
        $folderIds = array_map('intval', array_filter(explode('/', $item->path)));
        $rows = StorageShare::query()
            ->where(function ($q) use ($type, $id, $folderIds) {
                $q->where(fn ($q) => $q->where('item_type', $type)->where('item_id', $id))
                  ->orWhere(fn ($q) => $q->where('item_type', 'folder')->whereIn('item_id', $folderIds ?: [0]));
            })->orderBy('id')->get();

        $users = User::whereIn('id', $rows->where('grantee_type', 'user')->pluck('grantee_id')->all() ?: [0])->get(['id', 'name', 'email'])->keyBy('id');
        $roles = Role::whereIn('id', $rows->where('grantee_type', 'role')->pluck('grantee_id')->all() ?: [0])->get(['id', 'name'])->keyBy('id');
        $inheritedFrom = StorageFolder::whereIn('id', $rows->where('item_type', 'folder')->pluck('item_id')->all() ?: [0])->get()->keyBy('id');

        return response()->json($rows->map(function ($s) use ($users, $roles, $inheritedFrom, $type, $id) {
            $direct = $s->item_type === $type && (int) $s->item_id === $id;
            return [
                'id' => $s->id,
                'grantee_type' => $s->grantee_type,
                'grantee_id' => $s->grantee_id,
                'grantee' => $s->grantee_type === 'user'
                    ? $users[$s->grantee_id]?->only('id', 'name', 'email')
                    : ['id' => $s->grantee_id, 'name' => $roles[$s->grantee_id]?->name],
                'level' => $s->level,
                'inherited_from' => $direct ? null : ['id' => $s->item_id, 'name' => $this->displayName($inheritedFrom[$s->item_id])],
            ];
        })->values());
    }

    /**
     * Con quién se puede compartir. Superadmin y admin: con cualquier usuario del módulo y
     * con roles completos. Los demás: solo con usuarios que ya pertenecen a sus clientes o
     * sitios — el admin de un hotel no puede pasarle documentos a alguien de otra cadena.
     * Un rol abarca a todos los clientes, así que compartir con roles es solo de admins.
     */
    public function shareCandidates(Request $request, string $type, int $id): JsonResponse
    {
        $access = $this->access($request);
        $item = $this->item($type, $id);
        abort_unless($access->canShare($item), 403);
        $q = trim((string) $request->query('q', ''));

        $users = User::query()->where('is_active', true)->where('id', '!=', $request->user()->id)
            ->where(fn ($w) => $w->permission('storage.view')->orWhereHas('roles', fn ($r) => $r->where('name', 'superadmin')))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")));

        if (!$access->isFull()) {
            $users->whereIn('id', $this->scopeUserIds($access, $item) ?: [0]);
        }

        return response()->json([
            'users' => $users->orderBy('name')->limit(50)->get(['id', 'name', 'email'])->values(),
            'roles' => $access->isFull()
                ? Role::where('name', '!=', 'superadmin')->orderBy('name')->get(['id', 'name'])->values()
                : [],
        ]);
    }

    /** Usuarios que pertenecen a los clientes/sitios del elemento (o del usuario, si es libre). */
    private function scopeUserIds(StorageAccess $access, StorageFolder|StorageFile $item): array
    {
        if ($item->client_id) {
            $clientIds = [(int) $item->client_id];
            $siteIds = $item->site_id
                ? [(int) $item->site_id]
                : Site::where('client_id', $item->client_id)->pluck('id')->all();
        } else {
            $siteIds = array_merge($access->siteIds(), Site::whereIn('client_id', $access->clientIds() ?: [0])->pluck('id')->all());
            $clientIds = array_merge($access->clientIds(), Site::whereIn('id', $access->siteIds() ?: [0])->pluck('client_id')->all());
        }

        $db = \Illuminate\Support\Facades\DB::class;
        return collect()
            ->merge($db::table('client_user')->whereIn('client_id', $clientIds ?: [0])->pluck('user_id'))
            ->merge($db::table('client_engineers')->whereIn('client_id', $clientIds ?: [0])->pluck('user_id'))
            ->merge($db::table('solicitante_client')->whereIn('client_id', $clientIds ?: [0])->pluck('user_id'))
            ->merge($db::table('site_user')->whereIn('site_id', $siteIds ?: [0])->pluck('user_id'))
            ->merge($db::table('site_engineers')->whereIn('site_id', $siteIds ?: [0])->pluck('user_id'))
            ->merge($db::table('solicitante_site')->whereIn('site_id', $siteIds ?: [0])->pluck('user_id'))
            ->unique()->values()->all();
    }

    public function share(Request $request, string $type, int $id): JsonResponse
    {
        $access = $this->access($request);
        $item = $this->item($type, $id);
        abort_unless($access->canShare($item), 403, 'No puedes compartir esto.');

        $data = $request->validate([
            'grantee_type' => 'required|in:user,role',
            'grantee_id' => 'required|integer',
            'level' => 'required|in:view,upload,manage',
        ]);
        if ($type === 'file' && $data['level'] === 'upload') $data['level'] = 'view'; // en un archivo no hay dónde subir

        if ($data['grantee_type'] === 'role') {
            abort_unless($access->isFull(), 403, 'Solo un administrador puede compartir con un rol completo.');
            abort_unless(Role::whereKey($data['grantee_id'])->exists(), 422, 'Ese rol no existe.');
        } else {
            abort_unless(User::whereKey($data['grantee_id'])->exists(), 422, 'Ese usuario no existe.');
            abort_if(!$access->isFull() && !in_array((int) $data['grantee_id'], array_map('intval', $this->scopeUserIds($access, $item)), true),
                403, 'Solo puedes compartir con usuarios de tus clientes o sitios.');
        }

        $share = StorageShare::updateOrCreate(
            ['item_type' => $type, 'item_id' => $id, 'grantee_type' => $data['grantee_type'], 'grantee_id' => $data['grantee_id']],
            ['level' => $data['level'], 'created_by' => $request->user()->id],
        );
        StorageTree::log($request->user(), 'shared', $item, $data);

        return response()->json(['id' => $share->id], 201);
    }

    public function unshare(Request $request, StorageShare $share): JsonResponse
    {
        $access = $this->access($request);
        $item = $this->item($share->item_type, $share->item_id);
        abort_unless($access->canShare($item), 403, 'No puedes cambiar cómo se comparte esto.');

        $share->delete();
        StorageTree::log($request->user(), 'unshared', $item, $share->only('grantee_type', 'grantee_id', 'level'));

        return response()->json(['ok' => true]);
    }

    // ── Papelera (storage.archive) ──────────────────────────────────

    public function trash(Request $request, string $type, int $id): JsonResponse
    {
        $access = $this->access($request);
        abort_unless($access->canTrash(), 403, 'Solo un superadministrador puede mandar a la papelera.');
        $item = $this->item($type, $id);
        abort_if($item->trashed_at, 409, 'Ya está en la papelera.');

        $this->tree->trash($item, $request->user());
        StorageTree::log($request->user(), 'trashed', $item);

        return response()->json(['ok' => true]);
    }

    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $access = $this->access($request);
        abort_unless($access->canTrash(), 403, 'Solo un superadministrador puede restaurar.');
        $item = $this->item($type, $id);
        abort_unless($item->trashed_at, 409, 'No está en la papelera.');

        $this->tree->restore($item);
        StorageTree::log($request->user(), 'restored', $item);

        return response()->json(['ok' => true]);
    }

    /** Solo lo que se mandó a la papelera directamente (no su contenido). */
    public function trashIndex(Request $request): JsonResponse
    {
        $access = $this->access($request);
        abort_unless($access->canTrash(), 403);

        $folders = StorageFolder::where('trash_root_type', 'folder')->whereColumn('trash_root_id', 'id')->with(['parent'])->get();
        $files = StorageFile::where('trash_root_type', 'file')->whereColumn('trash_root_id', 'id')->with(['folder', 'uploader:id,name'])->get();
        $by = User::whereIn('id', $folders->pluck('trashed_by')->merge($files->pluck('trashed_by'))->filter()->unique()->all() ?: [0])->pluck('name', 'id');

        $rows = $folders->map(fn ($f) => ['type' => 'folder'] + $this->folderRow($f) + [
            'from' => $f->parent ? $this->displayName($f->parent) : null,
            'from_trashed' => (bool) $f->parent?->trashed_at,
            'trashed_at' => $f->trashed_at, 'trashed_by' => $by[$f->trashed_by] ?? null,
        ])->merge($files->map(fn ($f) => ['type' => 'file'] + $this->fileRow($f, $request->user()) + [
            'from' => $f->folder ? $this->displayName($f->folder) : null,
            'from_trashed' => (bool) $f->folder?->trashed_at,
            'trashed_at' => $f->trashed_at, 'trashed_by' => $by[$f->trashed_by] ?? null,
        ]))->sortByDesc('trashed_at')->values();

        return response()->json($rows);
    }

    // ── Bitácora ────────────────────────────────────────────────────

    public function activity(Request $request, string $type, int $id): JsonResponse
    {
        $access = $this->access($request);
        $item = $this->item($type, $id);
        abort_unless($access->canManage($item) || $access->canTrash(), 403, 'No puedes ver la bitácora de esto.');

        $events = StorageEvent::with('user:id,name')
            ->where(function ($q) use ($type, $id, $item) {
                $q->where('item_type', $type)->where('item_id', $id);
                // En una carpeta, también lo que pasó con los archivos que contiene hoy.
                if ($item instanceof StorageFolder) {
                    $q->orWhere(fn ($q) => $q->where('item_type', 'file')->whereIn('item_id', StorageFile::where('folder_id', $id)->select('id')));
                }
            })
            ->orderByDesc('created_at')->limit(300)->get();

        return response()->json($events->map(fn ($e) => [
            'id' => $e->id,
            'action' => $e->action,
            'item_type' => $e->item_type,
            'item_id' => $e->item_id,
            'item_name' => $e->item_name,
            'user' => $e->user?->only('id', 'name'),
            'meta' => $e->meta,
            'ip' => $e->ip,
            'created_at' => $e->created_at,
        ])->values());
    }

    // ── Formas de respuesta ─────────────────────────────────────────

    private function displayName(StorageFolder $f): string
    {
        // Las ancladas muestran el nombre VIGENTE de su cliente o sitio.
        return match ($f->kind) {
            'client' => $f->client?->name ?? $f->name,
            'site' => $f->site?->name ?? $f->name,
            default => $f->name,
        };
    }

    private function folderRow(StorageFolder $f): array
    {
        return [
            'id' => $f->id,
            'name' => $this->displayName($f),
            'kind' => $f->kind,
            'parent_id' => $f->parent_id,
            'created_at' => $f->created_at,
            'updated_at' => $f->updated_at,
        ];
    }

    private function fileRow(StorageFile $f, User $user): array
    {
        $thumb = null;
        if ($f->status === 'ready' && preg_match('#^(image|video)/|pdf#', (string) $f->mime_type) && !preg_match('#svg#', (string) $f->mime_type)) {
            // Expira al final del día siguiente: la URL es la misma todo el día y el navegador la cachea.
            $thumb = URL::temporarySignedRoute('storage.thumbnail', now()->addDay()->endOfDay(), ['file' => $f->id, 'u' => $user->id]);
        }

        return [
            'id' => $f->id,
            'name' => $f->name,
            'mime_type' => $f->mime_type,
            'size' => $f->size,
            'status' => $f->status,
            'folder_id' => $f->folder_id,
            'uploaded_by' => $f->relationLoaded('uploader') ? $f->uploader?->only('id', 'name') : null,
            'uploaded_at' => $f->uploaded_at,
            'thumbnail_url' => $thumb,
        ];
    }

    private function cleanName(string $name): string
    {
        // Sin caracteres de control ni diagonales (romperían la ruta en Drive y en las migas).
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name));
        abort_if($name === '' || $name === '.' || $name === '..', 422, 'El nombre no es válido.');
        return mb_substr($name, 0, 200);
    }
}
