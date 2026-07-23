<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Client;
use App\Models\Maintenance;
use App\Models\MaintenanceContractFile;
use App\Models\MaintenanceContractFrequency;
use App\Models\Site;
use App\Models\User;
use App\Services\Webhooks\WebhookDispatcher;
use App\Support\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MaintenanceController extends Controller
{
    private function authorizeSiteAccess(Request $request, Client $client, Site $site): void
    {
        abort_unless($site->client_id === $client->id, 404);

        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        // El admin-cliente solo accede a sitios de SUS clientes (antes pasaba sin verificar → fuga).
        if ($user->hasRole('admin-cliente') && $user->clientsAsAdmin()->where('clients.id', $client->id)->exists()) return;

        if ($user->hasRole('admin-sitio') && $site->admins()->where('users.id', $user->id)->exists()) return;

        // Ingeniero: puede ver los mantenimientos del sitio si está asignado a alguno
        if ($user->hasRole('ingeniero') &&
            Maintenance::where('site_id', $site->id)
                ->whereHas('engineers', fn ($q) => $q->where('users.id', $user->id))
                ->exists()
        ) return;

        abort(403, 'No tienes acceso a este sitio.');
    }

    // ── Sistemas disponibles (con directorio activo y al menos un dispositivo activo) ──

    public function availableSystems(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);

        $systems = DB::table('directories')
            ->join('catalogs', 'catalogs.id', '=', 'directories.catalog_id')
            ->where('directories.site_id', $site->id)
            ->where('directories.is_active', true)
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('devices')
                ->whereColumn('devices.directory_id', 'directories.id')
                ->where('devices.is_active', true)
                ->whereNull('devices.archived_at')
            )
            ->select('catalogs.id', 'catalogs.label')
            ->distinct()
            ->orderBy('catalogs.label')
            ->get();

        return response()->json($systems);
    }

    // ── Detalle público (accesible desde /maintenances/{id}) ─────────────────

    public function show(Request $request, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeMaintenanceAccess($request->user(), $maintenance);

        $maintenance->load(['system', 'site.client', 'engineers:id,name,email']);
        $maintenance->loadCount('engineers');

        return response()->json($maintenance);
    }

    private function authorizeMaintenanceAccess(\App\Models\User $user, Maintenance $maintenance): void
    {
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        if ($user->hasRole('admin-sitio') &&
            $user->sitesAsAdmin()->where('sites.id', $maintenance->site_id)->exists()
        ) return;

        if ($user->hasRole('admin-cliente')) {
            $maintenance->loadMissing('site');
            if ($user->clientsAsAdmin()->where('clients.id', $maintenance->site->client_id)->exists()) return;
        }

        if ($user->hasRole('ingeniero') &&
            $maintenance->engineers()->where('users.id', $user->id)->exists()
        ) return;

        abort(403, 'No tienes acceso a este mantenimiento.');
    }

    // ── Archivar / restaurar (fuera de las listas) ────────────────────────────
    public function archive(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.archive'), 403, 'No autorizado para archivar mantenimientos.');
        $this->authorizeMaintenanceAccess($request->user(), $maintenance);

        if (! $maintenance->archived_at) {
            $maintenance->update(['archived_at' => now()]);
        }

        return response()->json(['message' => 'Mantenimiento archivado.']);
    }

    public function restore(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.archive'), 403, 'No autorizado para restaurar mantenimientos.');
        $this->authorizeMaintenanceAccess($request->user(), $maintenance);

        if ($maintenance->archived_at) {
            $maintenance->update(['archived_at' => null]);
        }

        return response()->json(['message' => 'Mantenimiento restaurado.']);
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function index(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($request->user()->can('maintenances.view'), 403, 'Sin permiso para ver mantenimientos.');

        $showArchived = $request->boolean('archived');
        if ($showArchived) {
            abort_unless($request->user()->can('maintenances.archive'), 403, 'No autorizado para ver mantenimientos archivados.');
        }

        $maintenances = Maintenance::with(['system', 'engineers:id,name,email'])
            ->withCount('engineers')
            ->where('site_id', $site->id)
            ->when(! $showArchived, fn ($q) => $q->whereNull('maintenances.archived_at'))
            ->when($showArchived,   fn ($q) => $q->whereNotNull('maintenances.archived_at'))
            ->when($request->filled('status'),    fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($q) => $q->where('start_date', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn ($q) => $q->where('start_date', '<=', $request->date_to))
            ->orderByDesc('start_date')
            ->paginate($request->per_page ?? 20);

        return response()->json($maintenances);
    }

    public function store(Request $request, Client $client, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($request->user()->can('maintenances.create'), 403, 'Sin permiso para registrar mantenimientos.');

        $data = $request->validate([
            'catalog_id' => 'required|integer|exists:catalogs,id',
            'type'       => 'nullable|in:' . implode(',', Maintenance::TYPES),
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'notes'      => 'nullable|string|max:1000',
        ]);

        // Verificar que sea un sistema
        $system = Catalog::findOrFail($data['catalog_id']);
        abort_if($system->type !== Catalog::TYPE_SYSTEM, 422, 'El catálogo seleccionado no es un sistema válido.');

        // Verificar directorio activo con al menos un dispositivo activo
        $hasDevices = DB::table('directories')
            ->where('site_id', $site->id)
            ->where('catalog_id', $data['catalog_id'])
            ->where('is_active', true)
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('devices')
                ->whereColumn('devices.directory_id', 'directories.id')
                ->where('devices.is_active', true)
                ->whereNull('devices.archived_at')
            )
            ->exists();

        abort_unless($hasDevices, 422, "El sitio no tiene dispositivos activos en el directorio de '{$system->label}'.");

        // Verificar que no se solapen con otro mantenimiento activo del mismo sistema
        $this->checkOverlap($site->id, $data['catalog_id'], $data['start_date'], $data['end_date']);

        // Auto-derivar status según las fechas
        $today  = now()->toDateString();
        $status = match (true) {
            $data['start_date'] > $today => 'programado',
            $data['end_date']   < $today => 'completado',
            default                       => 'en_curso',
        };

        $maintenance = Maintenance::create([
            ...$data,
            'site_id'    => $site->id,
            'status'     => $status,
            'created_by' => $request->user()->id,
        ]);

        $maintenance->load('system');

        // Webhook saliente a los sistemas suscritos del cliente/sitio.
        app(WebhookDispatcher::class)->dispatch(
            WebhookEvent::MAINTENANCE_CREATED,
            $site->client_id,
            $site->id,
            WebhookEvent::maintenanceData($maintenance, $request->user()),
        );

        return response()->json(['message' => 'Mantenimiento registrado.', 'maintenance' => $maintenance], 201);
    }

    public function update(Request $request, Client $client, Site $site, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($maintenance->site_id === $site->id, 404);
        abort_unless($request->user()->can('maintenances.edit'), 403, 'Sin permiso para editar mantenimientos.');

        $data = $request->validate([
            'type'       => 'nullable|in:' . implode(',', Maintenance::TYPES),
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'status'     => 'required|in:programado,en_curso,completado,cancelado',
            'notes'      => 'nullable|string|max:1000',
        ]);

        // Verificar solapamiento excluyendo el propio registro
        if ($data['status'] !== 'cancelado') {
            $this->checkOverlap(
                $site->id,
                $maintenance->catalog_id,
                $data['start_date'],
                $data['end_date'],
                $maintenance->id
            );
        }

        $maintenance->update($data);
        $maintenance->load('system');

        // Webhook saliente con el mantenimiento actualizado.
        app(WebhookDispatcher::class)->dispatch(
            WebhookEvent::MAINTENANCE_UPDATED,
            $site->client_id,
            $site->id,
            WebhookEvent::maintenanceData($maintenance, $request->user()),
        );

        return response()->json(['message' => 'Mantenimiento actualizado.', 'maintenance' => $maintenance]);
    }

    // ── Frecuencias del contrato (override por mantenimiento) ─────────────────

    /** Frecuencias definidas a nivel mantenimiento (contrato). */
    public function frequencies(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.view'), 403);
        $this->authorizeMaintenanceAccess($request->user(), $maintenance);

        return response()->json(
            MaintenanceContractFrequency::where('maintenance_id', $maintenance->id)
                ->get(['device_type_id', 'activity_type_id', 'period_value', 'period_unit'])
        );
    }

    /** Reemplaza (sync) las frecuencias del mantenimiento. */
    public function syncFrequencies(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.manage-contract'), 403, 'Sin permiso para gestionar el contrato.');
        $this->authorizeMaintenanceAccess($request->user(), $maintenance);

        $data = $request->validate([
            'frequencies'                    => 'present|array',
            'frequencies.*.device_type_id'   => 'required|integer|exists:catalogs,id',
            'frequencies.*.activity_type_id' => 'required|integer|exists:catalogs,id',
            'frequencies.*.period_unit'      => 'required|in:days,months,years,as_needed',
            'frequencies.*.period_value'     => 'nullable|integer|min:1|max:9999|required_unless:frequencies.*.period_unit,as_needed',
        ]);

        $system = Catalog::findOrFail($maintenance->catalog_id);
        $validDeviceTypes   = $system->deviceTypes()->pluck('catalogs.id')->all();
        $validActivityTypes = $system->activityTypes()->pluck('catalogs.id')->all();

        DB::transaction(function () use ($maintenance, $data, $validDeviceTypes, $validActivityTypes) {
            MaintenanceContractFrequency::where('maintenance_id', $maintenance->id)->delete();

            foreach ($data['frequencies'] as $row) {
                if (! in_array((int) $row['device_type_id'], $validDeviceTypes, true)) continue;
                if (! in_array((int) $row['activity_type_id'], $validActivityTypes, true)) continue;

                MaintenanceContractFrequency::create([
                    'maintenance_id'   => $maintenance->id,
                    'device_type_id'   => $row['device_type_id'],
                    'activity_type_id' => $row['activity_type_id'],
                    'period_value'     => $row['period_unit'] === 'as_needed' ? null : $row['period_value'],
                    'period_unit'      => $row['period_unit'],
                ]);
            }
        });

        return response()->json(['message' => 'Frecuencias del contrato actualizadas.']);
    }

    // ── Archivos de contrato (referencia) ─────────────────────────────────────

    public function contractFiles(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.view'), 403);
        $this->authorizeMaintenanceAccess($request->user(), $maintenance);

        return response()->json(
            MaintenanceContractFile::where('maintenance_id', $maintenance->id)
                ->orderByDesc('id')
                ->get(['id', 'name', 'path', 'mime', 'size', 'created_at'])
        );
    }

    public function uploadContractFiles(Request $request, Maintenance $maintenance): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.manage-contract'), 403, 'Sin permiso para gestionar el contrato.');
        $this->authorizeMaintenanceAccess($request->user(), $maintenance);

        $request->validate([
            'files'   => 'required|array|min:1|max:20',
            'files.*' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png'],
        ], [
            'files.*.max'   => 'Cada archivo no puede superar 20 MB.',
            'files.*.mimes' => 'Formatos permitidos: PDF, Word, Excel o imágenes.',
        ]);

        $created = [];
        foreach ($request->file('files') as $file) {
            $ext  = strtolower($file->getClientOriginalExtension());
            $name = Str::uuid()->toString() . ($ext ? ".{$ext}" : '');
            $path = $file->storeAs('contract-files', $name, 'public');

            $created[] = MaintenanceContractFile::create([
                'maintenance_id' => $maintenance->id,
                'name'           => $file->getClientOriginalName(),
                'path'           => $path,
                'mime'           => $file->getClientMimeType(),
                'size'           => $file->getSize(),
                'uploaded_by'    => $request->user()->id,
            ]);
        }

        return response()->json(['message' => 'Archivos subidos.', 'files' => $created], 201);
    }

    public function deleteContractFile(Request $request, Maintenance $maintenance, MaintenanceContractFile $file): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.manage-contract'), 403, 'Sin permiso para gestionar el contrato.');
        $this->authorizeMaintenanceAccess($request->user(), $maintenance);
        abort_unless($file->maintenance_id === $maintenance->id, 404);

        if (Storage::disk('public')->exists($file->path)) {
            Storage::disk('public')->delete($file->path);
        }
        $file->delete();

        return response()->json(['message' => 'Archivo eliminado.']);
    }

    // ── Mantenimientos en el alcance del usuario ──────────────────────────────

    public function myMaintenances(Request $request): JsonResponse
    {
        $user = $request->user();

        $showArchived = $request->boolean('archived');
        if ($showArchived) {
            abort_unless($user->can('maintenances.archive'), 403, 'No autorizado para ver mantenimientos archivados.');
        }

        $query = Maintenance::with(['system', 'site.client'])
            // Oculta mantenimientos cuyo sitio o cliente esté archivado (cascada lógica);
            // sin esto, site.client llega null y el grid del front revienta.
            ->whereHas('site', fn ($q) => $q->whereHas('client'))
            ->when(! $showArchived, fn ($q) => $q->whereNull('maintenances.archived_at'))
            ->when($showArchived,   fn ($q) => $q->whereNotNull('maintenances.archived_at'))
            ->when($request->filled('status'),    fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($q) => $q->where('start_date', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn ($q) => $q->where('start_date', '<=', $request->date_to))
            ->when($request->filled('client_id'), fn ($q) => $q->whereHas('site', fn ($sq) => $sq->where('client_id', $request->client_id)))
            ->when($request->filled('site_id'),   fn ($q) => $q->where('site_id', $request->site_id))
            ->orderByDesc('start_date');

        if ($user->hasRole('ingeniero')) {
            $query->whereHas('engineers', fn ($q) => $q->where('users.id', $user->id));
        } elseif ($user->hasRole('admin-sitio')) {
            $siteIds = $user->sitesAsAdmin()->pluck('sites.id');
            $query->whereIn('site_id', $siteIds);
        } elseif ($user->hasRole('admin-cliente')) {
            $clientIds = $user->clientsAsAdmin()->pluck('clients.id');
            $query->whereHas('site', fn ($q) => $q->whereIn('client_id', $clientIds));
        }
        // superadmin / admin: sin filtro — ven todo

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    // ── Gestión de ingenieros en un mantenimiento ─────────────────────────────

    public function engineerIndex(Request $request, Client $client, Site $site, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($maintenance->site_id === $site->id, 404);

        return response()->json($maintenance->engineers()->select('users.id', 'users.name', 'users.email')->get());
    }

    public function engineerCandidates(Request $request, Client $client, Site $site, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($maintenance->site_id === $site->id, 404);

        $assignedIds = $maintenance->engineers()->pluck('users.id');

        $candidates = User::role('ingeniero')
            ->where('is_active', true)
            ->whereNotIn('id', $assignedIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json($candidates);
    }

    public function engineerStore(Request $request, Client $client, Site $site, Maintenance $maintenance): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($maintenance->site_id === $site->id, 404);
        abort_unless($request->user()->can('maintenances.assign-engineers'), 403, 'Sin permiso para asignar ingenieros.');

        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $user = User::findOrFail($request->user_id);
        abort_unless($user->hasRole('ingeniero'), 422, 'El usuario seleccionado no tiene el rol de ingeniero.');

        if ($maintenance->engineers()->where('users.id', $user->id)->exists()) {
            return response()->json(['message' => 'El ingeniero ya está asignado a este mantenimiento.'], 422);
        }

        $maintenance->engineers()->attach($user->id);

        return response()->json([
            'message'  => "{$user->name} asignado al mantenimiento.",
            'engineer' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    public function engineerDestroy(Request $request, Client $client, Site $site, Maintenance $maintenance, User $user): JsonResponse
    {
        $this->authorizeSiteAccess($request, $client, $site);
        abort_unless($maintenance->site_id === $site->id, 404);
        abort_unless($request->user()->can('maintenances.assign-engineers'), 403, 'Sin permiso para remover ingenieros.');

        $maintenance->engineers()->detach($user->id);

        return response()->json(['message' => "{$user->name} removido del mantenimiento."]);
    }

    // ── Crear mantenimiento desde /my-maintenances (scope por rol) ───────────

    public function quickCreate(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('maintenances.create'), 403, 'Sin permiso para registrar mantenimientos.');

        $data = $request->validate([
            'site_id'    => 'required|integer|exists:sites,id',
            'catalog_id' => 'required|integer|exists:catalogs,id',
            'type'       => 'nullable|in:' . implode(',', Maintenance::TYPES),
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'notes'      => 'nullable|string|max:1000',
        ]);

        $site = Site::findOrFail($data['site_id']);

        $this->verifyUserSiteAccess($request->user(), $site);

        $system = Catalog::findOrFail($data['catalog_id']);
        abort_if($system->type !== Catalog::TYPE_SYSTEM, 422, 'El catálogo seleccionado no es un sistema válido.');

        $hasDevices = DB::table('directories')
            ->where('site_id', $site->id)
            ->where('catalog_id', $data['catalog_id'])
            ->where('is_active', true)
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('devices')
                ->whereColumn('devices.directory_id', 'directories.id')
                ->where('devices.is_active', true)
                ->whereNull('devices.archived_at')
            )
            ->exists();

        abort_unless($hasDevices, 422, "El sitio no tiene dispositivos activos en el directorio de '{$system->label}'.");

        $this->checkOverlap($site->id, $data['catalog_id'], $data['start_date'], $data['end_date']);

        $today  = now()->toDateString();
        $status = match (true) {
            $data['start_date'] > $today => 'programado',
            $data['end_date']   < $today => 'completado',
            default                       => 'en_curso',
        };

        $maintenance = Maintenance::create([
            'site_id'    => $site->id,
            'catalog_id' => $data['catalog_id'],
            'type'       => $data['type'] ?? 'normal',
            'start_date' => $data['start_date'],
            'end_date'   => $data['end_date'],
            'notes'      => $data['notes'] ?? null,
            'status'     => $status,
            'created_by' => $request->user()->id,
        ]);

        $maintenance->load(['system', 'site.client']);

        return response()->json(['message' => 'Mantenimiento registrado.', 'maintenance' => $maintenance], 201);
    }

    private function verifyUserSiteAccess(\App\Models\User $user, Site $site): void
    {
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;
        if ($user->hasRole('admin-cliente') &&
            $user->clientsAsAdmin()->where('clients.id', $site->client_id)->exists()) return;
        if ($user->hasRole('admin-sitio') &&
            $site->admins()->where('users.id', $user->id)->exists()) return;
        abort(403, 'No tienes acceso a este sitio.');
    }

    // ── Helper de solapamiento ────────────────────────────────────────────────

    private function checkOverlap(
        int $siteId,
        int $catalogId,
        string $startDate,
        string $endDate,
        ?int $excludeId = null
    ): void {
        $conflict = Maintenance::where('site_id', $siteId)
            ->where('catalog_id', $catalogId)
            ->where('status', '!=', 'cancelado')
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first(['start_date', 'end_date']);

        if ($conflict) {
            $from = $conflict->start_date->format('d/m/Y');
            $to   = $conflict->end_date->format('d/m/Y');
            abort(422, "El período se solapa con un mantenimiento activo del mismo sistema ({$from} – {$to}).");
        }
    }
}
