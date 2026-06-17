<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Device;
use App\Models\Directory;
use App\Models\Site;
use App\Traits\ManagesDeviceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    use ManagesDeviceData;
    private function authorizeDirectoryAccess(Request $request, Client $client, Site $site, Directory $directory): void
    {
        abort_unless($site->client_id === $client->id, 404);
        abort_unless($directory->site_id === $site->id, 404);

        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin', 'admin-cliente'])) return;

        if ($user->hasRole('admin-sitio') && $site->admins()->where('users.id', $user->id)->exists()) return;

        abort(403, 'No tienes acceso a este directorio.');
    }

    public function index(Request $request, Client $client, Site $site, Directory $directory): JsonResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);

        $cfFilters = array_filter((array) $request->cf, fn($v) => $v !== null && $v !== '');
        $didSearch = trim((string) $request->did);
        // Clave del campo DID (puede no ser 'did' si se definió con otra clave).
        $didKey = trim((string) $request->did_key) ?: 'did';

        $devices = $directory->devices()
            ->when($request->search, fn ($q) => $q->where('name', 'ilike', "%{$request->search}%"))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN)))
            ->when(!empty($cfFilters), function ($q) use ($cfFilters) {
                foreach ($cfFilters as $key => $value) {
                    $q->whereRaw("custom_fields->>? = ?", [$key, $value]);
                }
            })
            ->when($didSearch !== '', fn ($q) => $q->whereRaw("custom_fields->>? ILIKE ?", [$didKey, "%{$didSearch}%"]))
            ->orderBy('created_at')
            ->paginate($request->per_page ?? 20);

        return response()->json($devices);
    }

    /** Devuelve los valores únicos por campo personalizado (para dropdowns de filtro) */
    public function fieldValues(Request $request, Client $client, Site $site, Directory $directory): JsonResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);

        $allFields = DB::table('devices')
            ->where('directory_id', $directory->id)
            ->where('is_active', true)
            ->whereNotNull('custom_fields')
            ->pluck('custom_fields');

        $map = [];
        foreach ($allFields as $json) {
            $fields = is_string($json) ? json_decode($json, true) : (array) $json;
            if (!is_array($fields)) continue;
            foreach ($fields as $key => $value) {
                if ($value === null || $value === '' || is_array($value)) continue;
                $map[$key][(string) $value] = true;
            }
        }

        $result = [];
        foreach ($map as $key => $valSet) {
            if (count($valSet) >= 2) {
                $vals = array_keys($valSet);
                sort($vals);
                $result[$key] = $vals;
            }
        }

        return response()->json($result);
    }

    public function store(Request $request, Client $client, Site $site, Directory $directory): JsonResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);

        $data = $request->validate([
            'custom_fields' => 'nullable|array',
        ]);

        $customFields = $data['custom_fields'] ?? [];
        $displayName  = $this->deriveDisplayName($customFields, $directory);
        $deviceType   = $this->deriveDeviceType($customFields);

        $device = DB::transaction(function () use ($directory, $customFields, $displayName, $deviceType, $request) {
            $device = $directory->devices()->create([
                'name'          => $displayName,
                'device_type'   => $deviceType,
                'status'        => 'operativo',
                'custom_fields' => $customFields ?: null,
                'is_active'     => true,
                'created_by'    => $request->user()->id,
            ]);

            $this->syncFieldValues($device, $directory, $customFields);

            return $device;
        });

        return response()->json(['message' => 'Dispositivo registrado correctamente.', 'device' => $device], 201);
    }

    public function show(Request $request, Client $client, Site $site, Directory $directory, Device $device): JsonResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);
        abort_unless($device->directory_id === $directory->id, 404);

        return response()->json($device);
    }

    public function update(Request $request, Client $client, Site $site, Directory $directory, Device $device): JsonResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);
        abort_unless($device->directory_id === $directory->id, 404);

        $data = $request->validate([
            'custom_fields' => 'nullable|array',
        ]);

        $customFields = $data['custom_fields'] ?? [];
        $displayName  = $this->deriveDisplayName($customFields, $directory);
        $deviceType   = $this->deriveDeviceType($customFields);

        DB::transaction(function () use ($device, $directory, $customFields, $displayName, $deviceType) {
            $device->update([
                'name'          => $displayName,
                'device_type'   => $deviceType,
                'custom_fields' => $customFields ?: null,
            ]);

            $this->syncFieldValues($device, $directory, $customFields);
        });

        return response()->json(['message' => 'Dispositivo actualizado correctamente.', 'device' => $device->fresh()]);
    }

    public function toggleStatus(Request $request, Client $client, Site $site, Directory $directory, Device $device): JsonResponse
    {
        $this->authorizeDirectoryAccess($request, $client, $site, $directory);
        abort_unless($device->directory_id === $directory->id, 404);

        $device->update(['is_active' => ! $device->is_active]);
        $status = $device->is_active ? 'activado' : 'desactivado';

        return response()->json(['message' => "Dispositivo {$status}.", 'device' => $device]);
    }

}
