<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExecutiveReportTemplate;
use App\Models\Site;
use App\Services\Reports\ExecutivePdf;
use App\Services\Reports\ExecutiveReport;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte ejecutivo de un sitio: el resumen que se entrega al cliente, mezclando
 * capturas de mantenimiento y eventos del periodo. Ver App\Services\Reports\ExecutiveReport
 * (cálculo) y ExecutivePdf (dibujo).
 *
 * Gateado por `reports.executive`, con el alcance por rol de siempre: nadie genera el
 * reporte de un sitio que no le toca.
 */
class ExecutiveReportController extends Controller
{
    private function authorizeSite(Request $request, Site $site): void
    {
        abort_unless($request->user()->can('reports.executive'), 403, 'No autorizado para esta acción.');

        $user = $request->user();
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;

        if ($user->hasRole('admin-cliente') && $user->clientsAsAdmin()->where('clients.id', $site->client_id)->exists()) return;
        if ($user->hasRole('admin-sitio') && $site->admins()->where('users.id', $user->id)->exists()) return;
        if ($user->sitesAsEngineer()->where('sites.id', $site->id)->exists()) return;
        if ($user->clientsAsEngineer()->where('clients.id', $site->client_id)->exists()) return;

        abort(403, 'No tienes acceso a este sitio.');
    }

    /** Sitio + periodo + sistema opcional, comunes a todas las acciones. */
    private function context(Request $request): array
    {
        $data = $request->validate([
            'site_id'   => 'required|integer|exists:sites,id',
            'system_id' => 'nullable|integer',
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $site = Site::with('client:id,name')->findOrFail($data['site_id']);
        $this->authorizeSite($request, $site);

        return [
            $site,
            $data['system_id'] ?? null,
            Carbon::parse($data['date_from'])->startOfDay(),
            Carbon::parse($data['date_to'])->endOfDay(),
        ];
    }

    /** Config del cuerpo (JSON) o, si no viene, la automática del propio reporte. */
    private function configFrom(Request $request, ExecutiveReport $report): array
    {
        $raw = $request->input('config');
        if (is_string($raw)) $raw = json_decode($raw, true);

        return is_array($raw) && $raw ? $raw : $report->defaultConfig();
    }

    /**
     * GET /reports/executive/sites — sitios a los que el usuario puede sacarle el
     * reporte, con los sistemas que tiene cada uno (los directorios que existen).
     * Va aparte de /events/sites para no colgar este reporte del permiso de eventos.
     */
    public function sites(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('reports.executive'), 403, 'No autorizado para esta acción.');

        $user = $request->user();
        $q    = Site::query()->with('client:id,name')->whereHas('client')->orderBy('name');

        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            // todos
        } elseif ($user->hasRole('admin-cliente')) {
            $q->whereIn('client_id', $user->clientsAsAdmin()->pluck('clients.id'));
        } elseif ($user->hasRole('admin-sitio')) {
            $q->whereIn('id', $user->sitesAsAdmin()->pluck('sites.id'));
        } else {
            $q->where(fn ($w) => $w
                ->whereIn('id', $user->sitesAsEngineer()->pluck('sites.id'))
                ->orWhereIn('client_id', $user->clientsAsEngineer()->pluck('clients.id')));
        }

        $sites = $q->get(['id', 'name', 'client_id']);

        $systems = \App\Models\Directory::query()
            ->join('catalogs', 'catalogs.id', '=', 'directories.catalog_id')
            ->whereIn('directories.site_id', $sites->pluck('id'))
            ->where('directories.is_active', true)
            ->distinct()
            ->get(['directories.site_id', 'catalogs.id as system_id', 'catalogs.label'])
            ->groupBy('site_id');

        return response()->json($sites->map(fn (Site $s) => [
            'id'      => $s->id,
            'name'    => $s->name,
            'client'  => $s->client?->name,
            'systems' => ($systems[$s->id] ?? collect())
                ->map(fn ($r) => ['id' => (int) $r->system_id, 'label' => $r->label])
                ->unique('id')->values(),
        ])->values());
    }

    /**
     * GET /reports/executive/options — insumos del configurador: campos del directorio
     * agrupables, servicios disponibles y la configuración sugerida para el periodo.
     */
    public function options(Request $request): JsonResponse
    {
        [$site, $systemId, $from, $to] = $this->context($request);

        $report = new ExecutiveReport($site, $systemId, $from, $to);

        return response()->json([
            'site'            => ['id' => $site->id, 'name' => $site->name, 'client' => $site->client?->name],
            'group_fields'    => $report->groupableFields()->values(),
            'services'        => $report->availableServices(),
            'default_config'  => $report->defaultConfig(),
            'templates'       => ExecutiveReportTemplate::where('site_id', $site->id)
                ->when($systemId, fn ($q) => $q->where(fn ($w) => $w->whereNull('system_id')->orWhere('system_id', $systemId)))
                ->orderBy('name')
                ->get(['id', 'name', 'system_id', 'config']),
        ]);
    }

    /** GET /reports/executive/preview — el mismo tablero del PDF, en JSON. */
    public function preview(Request $request): JsonResponse
    {
        [$site, $systemId, $from, $to] = $this->context($request);

        $report = new ExecutiveReport($site, $systemId, $from, $to);

        return response()->json(
            (new ExecutiveReport($site, $systemId, $from, $to, $this->configFrom($request, $report)))->build()
        );
    }

    /** GET /reports/executive/pdf — el entregable. */
    public function pdf(Request $request): StreamedResponse
    {
        [$site, $systemId, $from, $to] = $this->context($request);

        $report = new ExecutiveReport($site, $systemId, $from, $to);
        $data   = (new ExecutiveReport($site, $systemId, $from, $to, $this->configFrom($request, $report)))->build();

        $binary = (new ExecutivePdf($data))
            ->withSignature($request->input('signature'))
            ->render();

        app(\App\Support\ActivityLogger::class)->log(
            'maintenances',
            'exported',
            "Generó el reporte ejecutivo de «{$site->name}» ({$data['meta']['period_label']})",
            ['source' => 'request'],
        );

        $name = Str::slug($site->name . '-' . $data['meta']['period_label']) . '.pdf';

        return response()->streamDownload(fn () => print($binary), $name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    // ── Plantillas ────────────────────────────────────────────────────────────

    /** POST /reports/executive/templates — guardar la configuración para reusarla. */
    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_id'   => 'required|integer|exists:sites,id',
            'system_id' => 'nullable|integer',
            'name'      => 'required|string|max:120',
            'config'    => 'required|array',
        ]);

        $site     = Site::findOrFail($data['site_id']);
        $systemId = $data['system_id'] ?? null;
        $this->authorizeSite($request, $site);

        $template = ExecutiveReportTemplate::updateOrCreate(
            ['site_id' => $site->id, 'system_id' => $systemId, 'name' => $data['name']],
            ['config' => $data['config'], 'created_by' => $request->user()->id],
        );

        return response()->json([
            'message'  => 'Plantilla guardada.',
            'template' => $template->only(['id', 'name', 'system_id', 'config']),
        ], 201);
    }

    /** DELETE /reports/executive/templates/{template} */
    public function destroyTemplate(Request $request, ExecutiveReportTemplate $template): JsonResponse
    {
        $site = Site::findOrFail($template->site_id);
        $this->authorizeSite($request, $site);

        $template->delete();

        return response()->json(['message' => 'Plantilla eliminada.']);
    }
}
