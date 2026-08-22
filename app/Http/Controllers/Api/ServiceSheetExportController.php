<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateServiceSheetsZip;
use App\Models\ServiceSheetExport;
use App\Models\Site;
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
            'site_id' => 'required|exists:sites,id',
            'from'    => 'required|date',
            'to'      => 'required|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to   = Carbon::parse($data['to'])->startOfDay();
        abort_if($from->diffInDays($to) > 31, 422, 'El rango de fechas no puede ser mayor a 31 días.');

        $site = Site::findOrFail($data['site_id']);
        $this->assertSiteAccess($user, $site);

        $export = ServiceSheetExport::create([
            'client_id'    => $site->client_id,
            'site_id'      => $site->id,
            'from_date'    => $from->toDateString(),
            'to_date'      => $to->toDateString(),
            'status'       => ServiceSheetExport::STATUS_PENDING,
            'requested_by' => $user->id,
            // Branding por dominio (white-label): se resuelve aquí porque el Job corre en
            // la cola sin request, y allAsMap() sin tenant tomaría el 'default'.
            'tenant'       => \App\Support\Tenant::fromRequest($request),
            // Cierre de conformidad elegido por quien solicita el ZIP.
            'signature'    => in_array($request->input('signature'), ['end', 'page'], true)
                ? $request->input('signature')
                : null,
            'signature_align' => in_array($request->input('signature_align'), ['left', 'center', 'right'], true)
                ? $request->input('signature_align')
                : null,
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

        return response()->json($serviceSheetExport->load('client:id,name', 'site:id,name'));
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

        $label = optional($serviceSheetExport->site)->name
            ?? optional($serviceSheetExport->client)->name ?? 'sitio';
        $name = 'hojas-servicio-' . \Illuminate\Support\Str::slug($label) . '-'
            . $serviceSheetExport->from_date->format('Ymd') . '-' . $serviceSheetExport->to_date->format('Ymd') . '.zip';

        return Storage::disk('local')->download($serviceSheetExport->file_path, $name);
    }

    private function assertSiteAccess(User $user, Site $site): void
    {
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        if ($user->hasRole('admin-cliente')) {
            abort_unless($user->clientsAsAdmin()->where('clients.id', $site->client_id)->exists(),
                403, 'No tienes acceso a este sitio.');
            return;
        }
        if ($user->hasRole('admin-sitio')) {
            abort_unless($user->sitesAsAdmin()->where('sites.id', $site->id)->exists(),
                403, 'No tienes acceso a este sitio.');
            return;
        }
        if ($user->hasRole('ingeniero')) {
            $viaClient = Site::whereIn('client_id', $user->clientsAsEngineer()->pluck('clients.id'))
                ->where('id', $site->id)->exists();
            $direct = $user->sitesAsEngineer()->where('sites.id', $site->id)->exists();
            abort_unless($viaClient || $direct, 403, 'No tienes acceso a este sitio.');
        }
        // Otros roles con events.view: el selector /events/sites ya acota lo que pueden elegir.
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
