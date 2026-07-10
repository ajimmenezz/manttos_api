<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\ActivityTypeField;
use App\Models\SystemField;
use Illuminate\Support\Collection;

/**
 * Filtros por campo del **directorio** (custom_fields del dispositivo) y por campo de
 * **formulario** (field_values de la actividad) para la Bitácora y el Dashboard de un
 * mantenimiento. Modo auto: `select` (baja cardinalidad, expone valores) o `text`
 * ("contiene", alta cardinalidad como área/ubicación/DID). Compartido por
 * `MaintenanceDashboardController` y `MaintenanceActivityController`.
 */
trait MaintenanceActivityFilters
{
    /** Sobre este nº de valores distintos, el campo se filtra por "contiene" (texto). */
    private int $filterSelectThreshold = 40;

    /** Tipos de campo que NO se pueden filtrar. */
    private array $filterSkipTypes = ['image', 'signature', 'leyenda'];

    /** Valores escalares (strings) de un valor de custom_fields / field_values. */
    private function valueStrings($v): array
    {
        if ($v === null || $v === '') return [];
        if (is_bool($v)) return [$v ? 'Sí' : 'No'];
        if (is_array($v)) {
            return collect($v)
                ->filter(fn ($x) => $x !== null && $x !== '')
                ->map(fn ($x) => is_bool($x) ? ($x ? 'Sí' : 'No') : (string) $x)
                ->values()->all();
        }
        return [(string) $v];
    }

    /**
     * Metadatos de filtros disponibles + mapas de modo, calculados sobre el universo base.
     * @param Collection $baseDevices     Device con custom_fields (sitio+sistema, activos).
     * @param Collection $baseActivities  MaintenanceActivity con activity_type_id + field_values.
     * @param Collection $activityTypes   Catalog id+label (tipos enlazados al sistema).
     */
    private function maintenanceFilterMeta(int $systemId, Collection $baseDevices, Collection $baseActivities, Collection $activityTypes): array
    {
        // ── Campos del directorio ──────────────────────────────────────────
        $dirFields = SystemField::where('catalog_id', $systemId)
            ->where('is_active', true)
            ->whereNotIn('field_type', $this->filterSkipTypes)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['field_key', 'label', 'field_type']);

        $directory = [];
        $dirModes  = [];
        foreach ($dirFields as $f) {
            $vals = $baseDevices
                ->flatMap(fn ($d) => $this->valueStrings(
                    is_array($d->custom_fields) ? ($d->custom_fields[$f->field_key] ?? null) : null
                ))
                ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
            if ($vals->isEmpty()) continue;

            $mode = $vals->count() <= $this->filterSelectThreshold ? 'select' : 'text';
            $dirModes[$f->field_key] = $mode;
            $directory[] = [
                'key'        => $f->field_key,
                'label'      => $f->label,
                'field_type' => $f->field_type,
                'mode'       => $mode,
                'values'     => $mode === 'select' ? $vals->all() : [],
            ];
        }

        // ── Campos de formulario (por tipo de actividad × sistema) ─────────
        $typeIds    = $activityTypes->pluck('id');
        $typeLabels = $activityTypes->pluck('label', 'id');
        $actsByType = $baseActivities->groupBy('activity_type_id');

        $formFields = ActivityTypeField::whereIn('activity_type_id', $typeIds)
            ->where('system_id', $systemId)
            ->where('is_active', true)
            ->whereNotIn('field_type', $this->filterSkipTypes)
            ->orderBy('activity_type_id')->orderBy('sort_order')->orderBy('id')
            ->get(['activity_type_id', 'field_key', 'label', 'field_type']);

        $form      = [];
        $formModes = [];
        foreach ($formFields as $f) {
            $key  = $f->activity_type_id . ':' . $f->field_key;
            $acts = $actsByType->get($f->activity_type_id, collect());
            $vals = $acts
                ->flatMap(fn ($a) => $this->valueStrings(($a->field_values ?? [])[$f->field_key] ?? null))
                ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
            if ($vals->isEmpty()) continue;

            $mode = $vals->count() <= $this->filterSelectThreshold ? 'select' : 'text';
            $formModes[$key] = $mode;
            $form[] = [
                'key'              => $key,
                'activity_type_id' => (int) $f->activity_type_id,
                'activity_type'    => $typeLabels[$f->activity_type_id] ?? '',
                'field_key'        => $f->field_key,
                'label'            => $f->label,
                'field_type'       => $f->field_type,
                'mode'             => $mode,
                'values'           => $mode === 'select' ? $vals->all() : [],
            ];
        }

        return [
            'available'  => ['directory' => $directory, 'form' => $form],
            'dir_modes'  => $dirModes,
            'form_modes' => $formModes,
        ];
    }

    /** Filtros enviados por el request: `dir_filters` + `form_filters` (JSON o array). */
    private function parseMaintenanceFilters($request): array
    {
        $decode = function ($raw) {
            if (is_array($raw)) return $raw;
            if (is_string($raw) && $raw !== '') {
                $d = json_decode($raw, true);
                return is_array($d) ? $d : [];
            }
            return [];
        };
        $clean = fn ($arr) => collect($arr)->filter(fn ($v) => $v !== '' && $v !== null)->all();

        return [
            'dir'  => $clean($decode($request->input('dir_filters'))),
            'form' => $clean($decode($request->input('form_filters'))),
        ];
    }

    /** Aplica los filtros de directorio a un query de Device (en SQL). */
    private function applyDirectoryFilters($deviceQuery, array $dirFilters, array $dirModes): void
    {
        foreach ($dirFilters as $key => $val) {
            $mode = $dirModes[$key] ?? 'select';
            if ($mode === 'text') {
                $deviceQuery->whereRaw('custom_fields->>? ilike ?', [$key, '%' . $val . '%']);
            } else {
                $deviceQuery->where(function ($q) use ($key, $val) {
                    $q->whereRaw('custom_fields->>? = ?', [$key, (string) $val]);
                    if ($val === 'Sí') $q->orWhereRaw('custom_fields->>? = ?', [$key, 'true']);
                    if ($val === 'No') $q->orWhereRaw('custom_fields->>? = ?', [$key, 'false']);
                });
            }
        }
    }

    /**
     * ¿La actividad cumple TODOS los filtros de formulario que aplican a su tipo?
     * Un filtro `{atid}:{key}` sólo aplica a las actividades de ese tipo; las de otros
     * tipos lo ignoran (pasan). Así, filtrar "Preventivo.Estado=Falla" no borra las
     * actividades de otros tipos, sólo restringe las de Preventivo.
     */
    private function activityPassesFormFilters($activity, array $formFilters, array $formModes): bool
    {
        foreach ($formFilters as $key => $val) {
            [$atid, $fkey] = array_pad(explode(':', (string) $key, 2), 2, null);
            if ($fkey === null || (int) $atid !== (int) $activity->activity_type_id) continue;

            $strings = $this->valueStrings(($activity->field_values ?? [])[$fkey] ?? null);
            $mode    = $formModes[$key] ?? 'select';
            $ok = $mode === 'text'
                ? collect($strings)->contains(fn ($s) => stripos($s, (string) $val) !== false)
                : in_array((string) $val, $strings, true);
            if (!$ok) return false;
        }
        return true;
    }
}
