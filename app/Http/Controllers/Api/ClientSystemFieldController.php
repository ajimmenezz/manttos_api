<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Client;
use App\Models\SystemField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientSystemFieldController extends Controller
{
    private function ensureCanManage(Request $request): void
    {
        abort_unless($request->user()->can('system-config.manage'), 403, 'No autorizado para esta acción.');
    }

    private function resolveSystem(Catalog $system): void
    {
        abort_if($system->type !== Catalog::TYPE_SYSTEM, 404, 'No es un sistema válido.');
    }

    private function ensureBaseTemplateExists(Catalog $system): void
    {
        $hasBase = SystemField::where('catalog_id', $system->id)
            ->whereNull('client_id')
            ->where('is_active', true)
            ->exists();

        abort_unless($hasBase, 422, 'El sistema no tiene plantilla base activa. Configura primero los campos en la sección de sistemas.');
    }

    // ── Lista de sistemas con plantilla base (para el tab del cliente) ─────────

    public function systemsWithTemplates(Request $request, Client $client): JsonResponse
    {
        $this->ensureCanManage($request);

        // Sistemas que tienen al menos un campo base activo
        $systems = Catalog::where('type', Catalog::TYPE_SYSTEM)
            ->where('is_active', true)
            ->whereHas('fields', fn ($q) => $q->whereNull('client_id')->where('is_active', true))
            ->orderBy('label')
            ->get(['id', 'label', 'is_active']);

        // Conteo de campos extra del cliente agrupado por sistema
        $clientCounts = SystemField::whereIn('catalog_id', $systems->pluck('id'))
            ->where('client_id', $client->id)
            ->groupBy('catalog_id')
            ->selectRaw('catalog_id, count(*) as total')
            ->pluck('total', 'catalog_id');

        $baseCounts = SystemField::whereIn('catalog_id', $systems->pluck('id'))
            ->whereNull('client_id')
            ->where('is_active', true)
            ->groupBy('catalog_id')
            ->selectRaw('catalog_id, count(*) as total')
            ->pluck('total', 'catalog_id');

        return response()->json(
            $systems->map(fn ($s) => [
                'id'                  => $s->id,
                'label'               => $s->label,
                'base_fields_count'   => $baseCounts[$s->id]  ?? 0,
                'client_fields_count' => $clientCounts[$s->id] ?? 0,
            ])
        );
    }

    // ── Campos extra del cliente para un sistema ──────────────────────────────

    public function index(Request $request, Client $client, Catalog $system): JsonResponse
    {
        $this->ensureCanManage($request);
        $this->resolveSystem($system);

        $fields = SystemField::where('catalog_id', $system->id)
            ->where('client_id', $client->id)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        return response()->json($fields);
    }

    public function store(Request $request, Client $client, Catalog $system): JsonResponse
    {
        $this->ensureCanManage($request);
        $this->resolveSystem($system);
        $this->ensureBaseTemplateExists($system);

        $data = $request->validate([
            'label'             => 'required|string|max:100',
            'field_key'         => 'required|string|max:60|regex:/^[a-z][a-z0-9_]*$/',
            'field_type'        => 'required|in:text,number,date,boolean,list',
            'catalog_type'      => 'nullable|string|required_if:field_type,list',
            'is_required'       => 'boolean',
            'max_length'        => 'nullable|integer|min:1|max:5000',
            'sort_order'        => 'nullable|integer|min:0',
            'show_in_dashboard' => 'boolean',
        ]);

        abort_if(strtolower(trim($data['label'])) === 'id', 422, '"ID" es un nombre reservado por el sistema.');

        // La clave no puede estar en la plantilla base
        $existsInBase = SystemField::where('catalog_id', $system->id)
            ->whereNull('client_id')
            ->where('field_key', $data['field_key'])
            ->exists();

        abort_if($existsInBase, 422, "La clave '{$data['field_key']}' ya existe en la plantilla base del sistema. Usa una clave diferente.");

        // Ni estar ya definida para este cliente+sistema
        $existsForClient = SystemField::where('catalog_id', $system->id)
            ->where('client_id', $client->id)
            ->where('field_key', $data['field_key'])
            ->exists();

        abort_if($existsForClient, 422, "Ya existe un campo con esa clave en la plantilla de este cliente para este sistema.");

        $field = SystemField::create([
            ...$data,
            'catalog_id'  => $system->id,
            'client_id'   => $client->id,
            'is_active'   => true,
            'sort_order'  => $data['sort_order'] ?? (
                SystemField::where('catalog_id', $system->id)
                    ->where('client_id', $client->id)
                    ->max('sort_order') + 1
            ),
            'created_by'  => $request->user()->id,
        ]);

        return response()->json(['message' => 'Campo personalizado creado.', 'field' => $field], 201);
    }

    public function update(Request $request, Client $client, Catalog $system, SystemField $field): JsonResponse
    {
        $this->ensureCanManage($request);
        $this->resolveSystem($system);
        abort_unless($field->catalog_id === $system->id && $field->client_id === $client->id, 404);

        $data = $request->validate([
            'label'             => 'required|string|max:100',
            'field_type'        => 'required|in:text,number,date,boolean,list',
            'catalog_type'      => 'nullable|string|required_if:field_type,list',
            'is_required'       => 'boolean',
            'max_length'        => 'nullable|integer|min:1|max:5000',
            'show_in_dashboard' => 'boolean',
        ]);

        abort_if(strtolower(trim($data['label'])) === 'id', 422, '"ID" es un nombre reservado por el sistema.');

        $field->update($data);

        return response()->json(['message' => 'Campo actualizado.', 'field' => $field]);
    }

    public function toggleStatus(Request $request, Client $client, Catalog $system, SystemField $field): JsonResponse
    {
        $this->ensureCanManage($request);
        $this->resolveSystem($system);
        abort_unless($field->catalog_id === $system->id && $field->client_id === $client->id, 404);

        $field->update(['is_active' => ! $field->is_active]);
        $status = $field->is_active ? 'activado' : 'desactivado';

        return response()->json(['message' => "Campo {$status}.", 'field' => $field]);
    }

    public function toggleDashboard(Request $request, Client $client, Catalog $system, SystemField $field): JsonResponse
    {
        $this->ensureCanManage($request);
        $this->resolveSystem($system);
        abort_unless($field->catalog_id === $system->id && $field->client_id === $client->id, 404);

        $field->update(['show_in_dashboard' => ! $field->show_in_dashboard]);
        $status = $field->show_in_dashboard ? 'incluido en' : 'excluido del';

        return response()->json(['message' => "Campo {$status} dashboard.", 'field' => $field]);
    }

    public function destroy(Request $request, Client $client, Catalog $system, SystemField $field): JsonResponse
    {
        $this->ensureCanManage($request);
        $this->resolveSystem($system);
        abort_unless($field->catalog_id === $system->id && $field->client_id === $client->id, 404);

        $fieldKey = $field->field_key;

        \DB::transaction(function () use ($field, $fieldKey, $system, $client) {
            \DB::table('device_field_values')->where('system_field_id', $field->id)->delete();

            // Limpia la clave del JSONB solo en dispositivos de este cliente
            \DB::table('devices')
                ->whereIn('directory_id', function ($q) use ($system, $client) {
                    $q->select('d.id')
                        ->from('directories as d')
                        ->join('sites as s', 's.id', '=', 'd.site_id')
                        ->where('d.catalog_id', $system->id)
                        ->where('s.client_id', $client->id);
                })
                ->whereNotNull('custom_fields')
                ->update(['custom_fields' => \DB::raw("custom_fields - '{$fieldKey}'")]);

            $field->delete();
        });

        return response()->json(['message' => "Campo '{$field->label}' eliminado."]);
    }

    public function reorder(Request $request, Client $client, Catalog $system): JsonResponse
    {
        $this->ensureCanManage($request);
        $this->resolveSystem($system);

        $request->validate([
            'field_ids'   => 'required|array',
            'field_ids.*' => 'integer|exists:system_fields,id',
        ]);

        foreach ($request->field_ids as $order => $fieldId) {
            SystemField::where('id', $fieldId)
                ->where('catalog_id', $system->id)
                ->where('client_id', $client->id)
                ->update(['sort_order' => $order]);
        }

        return response()->json(['message' => 'Orden actualizado.']);
    }
}
