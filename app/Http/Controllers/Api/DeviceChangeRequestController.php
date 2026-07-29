<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceChangeRequest;
use App\Models\SystemField;
use App\Traits\ManagesDeviceData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Solicitudes de cambio a los datos del directorio de un dispositivo, hechas desde un evento.
 *
 * Dos permisos:
 *  - `devices.request-change` (todos los ingenieros): SOLICITAR un cambio. No aplica nada; sólo
 *    registra el antes/después para revisión.
 *  - `devices.apply-change` (superadmin / coordinador): APROBAR y aplicar, escribiendo los
 *    `custom_fields` del dispositivo (con la misma sincronización de índice que la edición normal).
 *
 * Todo queda auditado en `device_change_requests` (quién, cuándo, valor anterior y nuevo, motivo).
 */
class DeviceChangeRequestController extends Controller
{
    use ManagesDeviceData;

    /** Solicitudes de un dispositivo (o de un evento). Visible para quien puede solicitar o aplicar. */
    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can('devices.request-change') || $request->user()->can('devices.apply-change'),
            403, 'No autorizado para esta acción.'
        );

        $data = $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'event_id'  => ['nullable', 'integer', 'exists:events,id'],
        ]);
        abort_if(empty($data['device_id']) && empty($data['event_id']), 422, 'Indica el dispositivo o el evento.');

        $rows = DeviceChangeRequest::with(['requester:id,name', 'reviewer:id,name'])
            ->when(! empty($data['device_id']), fn ($q) => $q->where('device_id', $data['device_id']))
            ->when(! empty($data['event_id']), fn ($q) => $q->where('event_id', $data['event_id']))
            ->orderByDesc('id')
            ->get()
            ->map(fn (DeviceChangeRequest $r) => $this->present($r));

        return response()->json(['data' => $rows, 'can_apply' => $request->user()->can('devices.apply-change')]);
    }

    /** Registra una solicitud de cambio (no aplica nada). */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('devices.request-change'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'device_id'        => ['required', 'integer', 'exists:devices,id'],
            'event_id'         => ['nullable', 'integer', 'exists:events,id'],
            'changes'          => ['required', 'array', 'min:1'],
            'changes.*.field_key' => ['required', 'string', 'max:120'],
            'changes.*.value'  => ['present'],
            'note'             => ['nullable', 'string', 'max:1000'],
        ]);

        $device = Device::with('directory.site')->findOrFail($data['device_id']);
        $systemId = (int) $device->directory->catalog_id;
        $clientId = optional($device->directory->site)->client_id;
        $defs = $this->fieldDefs($systemId, $clientId);
        $cf = is_array($device->custom_fields) ? $device->custom_fields : [];

        // Sólo los campos que REALMENTE cambian; guarda el valor anterior y el nuevo + etiqueta.
        $changes = [];
        foreach ($data['changes'] as $c) {
            $key = $c['field_key'];
            if (! isset($defs[$key])) {
                continue; // ignora claves que no son del directorio
            }
            $new = $c['value'];
            $old = $cf[$key] ?? null;
            if ($this->normalize($old) === $this->normalize($new)) {
                continue; // sin cambio real
            }
            $changes[] = [
                'field_key'  => $key,
                'label'      => $defs[$key]['label'],
                'field_type' => $defs[$key]['field_type'],
                'old'        => $old,
                'new'        => $new,
            ];
        }

        abort_if($changes === [], 422, 'No hay cambios respecto a los datos actuales.');

        $req = DeviceChangeRequest::create([
            'device_id'    => $device->id,
            'event_id'     => $data['event_id'] ?? null,
            'requested_by' => $request->user()->id,
            'changes'      => $changes,
            'note'         => $data['note'] ?? null,
            'status'       => 'pending',
        ]);

        return response()->json(['data' => $this->present($req->load(['requester:id,name']))], 201);
    }

    /** Aprueba y aplica la solicitud: escribe los custom_fields del dispositivo (con sync de índice). */
    public function apply(Request $request, DeviceChangeRequest $deviceChangeRequest): JsonResponse
    {
        abort_unless($request->user()->can('devices.apply-change'), 403, 'No autorizado para esta acción.');
        abort_unless($deviceChangeRequest->status === 'pending', 422, 'Esta solicitud ya fue resuelta.');

        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);

        $device = Device::with('directory')->findOrFail($deviceChangeRequest->device_id);
        $directory = $device->directory;

        DB::transaction(function () use ($device, $directory, $deviceChangeRequest, $request, $data) {
            $cf = is_array($device->custom_fields) ? $device->custom_fields : [];
            foreach ($deviceChangeRequest->changes as $c) {
                $cf[$c['field_key']] = $c['new'];
            }

            $device->update([
                'name'          => $this->deriveDisplayName($cf, $directory),
                'device_type'   => $this->deriveDeviceType($cf),
                'custom_fields' => $cf ?: null,
            ]);
            $this->syncFieldValues($device, $directory, $cf);

            $deviceChangeRequest->update([
                'status'      => 'applied',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['review_note'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Cambio aplicado al dispositivo.',
            'data'    => $this->present($deviceChangeRequest->fresh(['requester:id,name', 'reviewer:id,name'])),
        ]);
    }

    /** Rechaza la solicitud (no toca el dispositivo). */
    public function reject(Request $request, DeviceChangeRequest $deviceChangeRequest): JsonResponse
    {
        abort_unless($request->user()->can('devices.apply-change'), 403, 'No autorizado para esta acción.');
        abort_unless($deviceChangeRequest->status === 'pending', 422, 'Esta solicitud ya fue resuelta.');

        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:1000']]);

        $deviceChangeRequest->update([
            'status'      => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
        ]);

        return response()->json([
            'message' => 'Solicitud rechazada.',
            'data'    => $this->present($deviceChangeRequest->fresh(['requester:id,name', 'reviewer:id,name'])),
        ]);
    }

    /** Campos del directorio (base + override por cliente) indexados por clave. */
    private function fieldDefs(int $systemId, ?int $clientId): array
    {
        $rows = SystemField::where('catalog_id', $systemId)
            ->where('is_active', true)
            ->when($clientId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhere('client_id', $clientId)),
                fn ($q) => $q->whereNull('client_id'))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['client_id', 'field_key', 'label', 'field_type']);

        return $rows->sortBy(fn ($f) => $f->client_id === null ? 0 : 1)
            ->keyBy('field_key')
            ->map(fn ($f) => ['label' => $f->label, 'field_type' => $f->field_type])
            ->all();
    }

    /** Normaliza un valor para comparar "antes vs después" (evita cambios falsos por tipo). */
    private function normalize($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_array($v)) {
            return json_encode($v);
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return trim((string) $v);
    }

    /** Forma de salida para la interfaz. */
    private function present(DeviceChangeRequest $r): array
    {
        return [
            'id'          => $r->id,
            'device_id'   => $r->device_id,
            'event_id'    => $r->event_id,
            'changes'     => $r->changes,
            'note'        => $r->note,
            'status'      => $r->status,
            'requested_by' => optional($r->requester)->name,
            'reviewed_by' => optional($r->reviewer)->name,
            'review_note' => $r->review_note,
            'reviewed_at' => optional($r->reviewed_at)?->toIso8601String(),
            'created_at'  => optional($r->created_at)?->toIso8601String(),
        ];
    }
}
