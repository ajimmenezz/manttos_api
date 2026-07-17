<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateServiceSheetsZip;
use App\Models\ServiceSheetExport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación en ZIP de las hojas de servicio de los eventos de un cliente en un rango
 * de fechas (≤ 31 días). Encola un job que procesa en segundo plano y notifica al
 * terminar; el ZIP se descarga con un endpoint autenticado.
 */
class ServiceSheetExportController extends Controller
{
    /** Solicita la generación del ZIP (procesa en segundo plano). */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('events.view'), 403, 'No autorizado para esta acción.');

        $data = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'from'      => 'required|date',
            'to'        => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->startOfDay();
        abort_if($from->diffInDays($to) > 31, 422, 'El rango de fechas no puede ser mayor a 31 días.');

        $this->assertClientAccess($user, (int) $data['client_id']);

        $export = ServiceSheetExport::create([
            'client_id'    => $data['client_id'],
            'from_date'    => $from->toDateString(),
            'to_date'      => $to->toDateString(),
            'status'       => ServiceSheetExport::STATUS_PENDING,
            'requested_by' => $user->id,
        ]);

        GenerateServiceSheetsZip::dispatch($export->id);

        return response()->json([
            'export'  => $export,
            'message' => 'Estamos generando tu ZIP; te avisaremos cuando esté listo.',
        ], 202);
    }

    /** Estado de una solicitud (para consultar el avance si se quiere). */
    public function show(Request $request, ServiceSheetExport $serviceSheetExport): JsonResponse
    {
        abort_unless($this->canAccess($request->user(), $serviceSheetExport), 403);

        return response()->json($serviceSheetExport->load('client:id,name'));
    }

    /** Descarga el ZIP generado (autenticado). */
    public function download(Request $request, ServiceSheetExport $serviceSheetExport): StreamedResponse
    {
        abort_unless($this->canAccess($request->user(), $serviceSheetExport), 403);
        abort_unless(
            $serviceSheetExport->status === ServiceSheetExport::STATUS_DONE
            && $serviceSheetExport->file_path
            && Storage::disk('local')->exists($serviceSheetExport->file_path),
            404, 'El archivo aún no está disponible.'
        );

        $client = optional($serviceSheetExport->client)->name ?? 'cliente';
        $name = 'hojas-servicio-' . \Illuminate\Support\Str::slug($client) . '-'
            . $serviceSheetExport->from_date->format('Ymd') . '-' . $serviceSheetExport->to_date->format('Ymd') . '.zip';

        return Storage::disk('local')->download($serviceSheetExport->file_path, $name);
    }

    private function assertClientAccess(User $user, int $clientId): void
    {
        if ($user->hasRole('admin-cliente')
            && ! $user->clientsAsAdmin()->where('clients.id', $clientId)->exists()) {
            abort(403, 'No tienes acceso a este cliente.');
        }
    }

    private function canAccess(User $user, ServiceSheetExport $export): bool
    {
        if ($export->requested_by === $user->id) return true;
        if (! $user->can('events.view')) return false;
        if ($user->hasRole('admin-cliente')) {
            return $user->clientsAsAdmin()->where('clients.id', $export->client_id)->exists();
        }
        return true;
    }
}
