<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReportSectionSetting;
use App\Models\Role;
use App\Models\User;
use App\Support\ReportSections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración de qué SECCIONES de los reportes-tablero (eventos y mantenimientos)
 * ve cada rol y cada usuario. Ver App\Support\ReportSections.
 *
 * Gateado por `report-sections.manage`; lo efectivo para el usuario en sesión viaja
 * dentro del payload de cada reporte (`hidden_sections`), no por aquí.
 */
class ReportSectionController extends Controller
{
    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('report-sections.manage'), 403, 'No autorizado para esta acción.');
    }

    /** Valida el ámbito y devuelve su etiqueta legible. */
    private function assertScope(string $scopeType, int $scopeId): string
    {
        abort_unless(in_array($scopeType, ['role', 'user'], true), 422, 'Ámbito desconocido.');

        if ($scopeType === 'role') {
            $role = Role::find($scopeId);
            abort_unless($role, 404, 'El rol no existe.');
            return $role->name;
        }

        $user = User::find($scopeId);
        abort_unless($user, 404, 'El usuario no existe.');
        return $user->name;
    }

    /** GET /report-sections/catalog — catálogo de reportes y secciones. */
    public function catalog(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['reports' => ReportSections::catalog()]);
    }

    /**
     * GET /report-sections/{scopeType}/{scopeId} — configuración guardada del ámbito.
     * Para un usuario incluye además lo que ocultan sus roles (`inherited_hidden`),
     * que es lo que hereda si no pone override propio.
     */
    public function show(Request $request, string $scopeType, int $scopeId): JsonResponse
    {
        $this->authorizeManage($request);
        $label = $this->assertScope($scopeType, $scopeId);

        $rows = ReportSectionSetting::where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->get()
            ->keyBy('report');

        $overrides = [];
        $inherited = [];

        foreach (ReportSections::REPORTS as $report) {
            $overrides[$report] = (object) ((array) ($rows[$report]->overrides ?? []));
            $inherited[$report] = $scopeType === 'user'
                ? $this->roleHiddenFor(User::find($scopeId), $report)
                : [];
        }

        return response()->json([
            'scope_type'       => $scopeType,
            'scope_id'         => $scopeId,
            'scope_label'      => $label,
            'overrides'        => $overrides,
            'inherited_hidden' => $inherited,
        ]);
    }

    /** Lo que ocultan los ROLES de un usuario (sin aplicar su override propio). */
    private function roleHiddenFor(User $user, string $report): array
    {
        $roleIds = $user->roles()->pluck('roles.id')->all();
        if (! $roleIds) return [];

        $hidden = [];
        $rows = ReportSectionSetting::where('report', $report)
            ->where('scope_type', 'role')
            ->whereIn('scope_id', $roleIds)
            ->get();

        foreach ($rows as $row) {
            foreach ((array) $row->overrides as $key => $visible) {
                if (! $visible) $hidden[$key] = true;
            }
        }

        return array_values(array_intersect(array_keys($hidden), ReportSections::keys($report)));
    }

    /**
     * PUT /report-sections/{scopeType}/{scopeId} — reemplaza la config de UN reporte.
     * Body: { report, overrides: { "<key>": bool } }. Las llaves desconocidas se
     * descartan; un override vacío borra la fila (vuelve a heredar todo).
     */
    public function update(Request $request, string $scopeType, int $scopeId): JsonResponse
    {
        $this->authorizeManage($request);
        $label = $this->assertScope($scopeType, $scopeId);

        $data = $request->validate([
            'report'      => 'required|string',
            'overrides'   => 'present|array',
            'overrides.*' => 'boolean',
        ]);

        ReportSections::assertReport($data['report']);
        $valid = ReportSections::keys($data['report']);

        $overrides = collect($data['overrides'])
            ->only($valid)
            // El ROL sólo guarda lo que oculta (false); "visible" es el default y no
            // se persiste. El USUARIO sí guarda ambos: su `true` recupera lo que el
            // rol le oculta.
            ->when($scopeType === 'role', fn ($c) => $c->reject(fn ($v) => (bool) $v))
            ->map(fn ($v) => (bool) $v)
            ->all();

        if (empty($overrides)) {
            ReportSectionSetting::where('scope_type', $scopeType)
                ->where('scope_id', $scopeId)
                ->where('report', $data['report'])
                ->delete();
        } else {
            ReportSectionSetting::updateOrCreate(
                ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'report' => $data['report']],
                ['overrides' => $overrides],
            );
        }

        app(\App\Support\ActivityLogger::class)->log(
            'config',
            'updated',
            "Actualizó las secciones del {$data['report']} para {$scopeType} «{$label}»",
            ['properties' => ['attributes' => $overrides]],
        );

        return response()->json([
            'message'   => 'Secciones actualizadas.',
            'overrides' => (object) $overrides,
        ]);
    }
}
