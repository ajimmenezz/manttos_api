<?php

namespace App\Services\Directory;

use App\Models\Directory;
use App\Models\SystemField;
use App\Services\Ai\Chat\Contracts\ChatProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Analista de Directorio: detecta posibles mejoras en los valores de texto del directorio
 * (sitio × sistema) y las aplica de forma controlada.
 *
 * Filosofía de MÍNIMO COSTO de tokens:
 *   1) Trabaja sobre VALORES DISTINTOS (no por dispositivo): un mismo "BASE SONORA Habitacion
 *      101" repetido 40 veces se analiza UNA vez y la corrección se propaga a los 40.
 *   2) Una pasada DETERMINISTA (gratis) arregla lo tipográfico: espacios entre texto y número,
 *      acentos/typos comunes y espacios colapsados.
 *   3) La IA sólo se invoca para los casos AMBIGUOS que la heurística no resuelve —típicamente
 *      cuando el nombre de un TIPO DE DISPOSITIVO se coló en la ubicación—, en UN solo prompt
 *      por lote con la lista de tipos como contexto y los valores enviados por índice.
 *
 * Salida: una lista de sugerencias {campo, valor_actual, sugerido, motivo, ocurrencias, origen}
 * que se revisa en la interfaz o se exporta a Excel, se edita y se reimporta para aplicar.
 */
class DirectoryAnalyzerService
{
    /** Tipos de campo cuyo contenido es texto libre susceptible de limpieza. */
    private const TEXT_TYPES = ['text', 'list', 'custom_list'];

    /** Máximo de valores distintos que se mandan a la IA por lote. */
    private const AI_BATCH = 120;

    public function __construct(private ?ChatProvider $provider = null) {}

    /**
     * Diccionario de correcciones tipográficas (clave SIN acentos y en minúsculas → forma correcta).
     * Sólo se aplica cuando arregla acentos u ortografía; NUNCA para recapitalizar una palabra que
     * ya está bien escrita (ver deterministic()). Por eso conviene incluir sólo palabras cuya forma
     * correcta difiere por acento o deletreo (no meras mayúsculas).
     */
    private const TYPOS = [
        'habitacion' => 'Habitación', 'habitacio' => 'Habitación', 'habitaciom' => 'Habitación',
        'recepcion' => 'Recepción',
        'bano' => 'Baño', 'vestibulo' => 'Vestíbulo',
        'sotano' => 'Sótano', 'salon' => 'Salón',
        'gym' => 'Gimnasio', 'vigilancion' => 'Vigilancia',
    ];

    /**
     * Campos del directorio que tiene sentido analizar (texto libre), con su etiqueta y tipo.
     * Excluye el DID (identificador) y los no-texto (número, fecha, booleano, imagen).
     *
     * @return array<int,array{key:string,label:string,field_type:string}>
     */
    public function analyzableFields(Directory $directory): array
    {
        return collect($this->fieldDefs($directory))
            ->filter(fn ($f) => in_array($f['field_type'], self::TEXT_TYPES, true))
            ->map(fn ($f) => ['key' => $f['field_key'], 'label' => $f['label'], 'field_type' => $f['field_type']])
            ->values()->all();
    }

    /**
     * Analiza los campos indicados y devuelve las sugerencias de mejora.
     *
     * @param  array<int,string>  $fieldKeys  claves de campo a revisar (vacío = todos los de texto)
     * @return array{fields:array,suggestions:array<int,array<string,mixed>>,ai_used:bool}
     */
    public function analyze(Directory $directory, array $fieldKeys = [], bool $useAi = true): array
    {
        $defs = collect($this->fieldDefs($directory))->keyBy('field_key');
        $targets = $this->analyzableFields($directory);
        if ($fieldKeys) {
            $targets = array_values(array_filter($targets, fn ($f) => in_array($f['key'], $fieldKeys, true)));
        }

        $deviceTypes = $this->deviceTypeVocabulary($directory);
        $aiUsed = false;
        $suggestions = [];

        foreach ($targets as $field) {
            $key = $field['key'];
            $distinct = $this->distinctValues($directory, $key); // [valueString => count]
            if (! $distinct) {
                continue;
            }

            $pending = []; // valores para IA: [valueString => true]
            foreach ($distinct as $value => $count) {
                $value = (string) $value;
                $det = $this->deterministic($value);
                if ($det !== null && $det !== $value) {
                    $suggestions[] = $this->row($field, $value, $det, 'Ortografía/formato', (int) $count, 'auto');
                } elseif ($this->looksLikeTypeLeak($value, $deviceTypes)) {
                    // Candidato a "tipo colado en la ubicación": lo resuelve la IA.
                    $pending[$value] = true;
                }
            }

            if ($useAi && $pending && $this->provider) {
                $aiFixes = $this->aiClean(array_keys($pending), $field['label'], $deviceTypes);
                foreach ($aiFixes as $original => $fix) {
                    if ($fix['suggested'] !== '' && $fix['suggested'] !== $original) {
                        $aiUsed = true;
                        $suggestions[] = $this->row(
                            $field, $original, $fix['suggested'],
                            $fix['reason'] ?: 'Se quitó el tipo de dispositivo de la ubicación',
                            (int) ($distinct[$original] ?? 0), 'ai'
                        );
                    }
                }
            }
        }

        // Ordena por más ocurrencias primero (mayor impacto).
        usort($suggestions, fn ($a, $b) => $b['occurrences'] <=> $a['occurrences']);

        return [
            'fields'      => $targets,
            'suggestions' => $suggestions,
            'ai_used'     => $aiUsed,
        ];
    }

    /**
     * Aplica los cambios aprobados: por cada {field_key, original, suggested} reemplaza el valor
     * en TODOS los dispositivos del directorio que aún lo tengan. Idempotente. Devuelve el total
     * de dispositivos actualizados.
     *
     * @param  array<int,array{field_key:string,original:string,suggested:string}>  $changes
     */
    public function apply(Directory $directory, array $changes): int
    {
        $affected = 0;
        foreach ($changes as $c) {
            $key = (string) ($c['field_key'] ?? '');
            $original = (string) ($c['original'] ?? '');
            $suggested = trim((string) ($c['suggested'] ?? ''));
            if ($key === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $key) || $suggested === '' || $suggested === $original) {
                continue;
            }

            $affected += DB::update(
                "UPDATE devices
                    SET custom_fields = jsonb_set(COALESCE(custom_fields, '{}'::jsonb), ?, to_jsonb(?::text), true),
                        updated_at = now()
                  WHERE directory_id = ?
                    AND archived_at IS NULL
                    AND custom_fields->>? = ?",
                ['{'.$key.'}', $suggested, $directory->id, $key, $original]
            );
        }

        return $affected;
    }

    // ── Heurística determinista (gratis) ────────────────────────────────────────

    /** Normaliza tipografía: espacios texto↔número, colapsa espacios y corrige typos del diccionario. */
    private function deterministic(string $value): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $value));
        if ($v === '') {
            return null;
        }
        // Separa SÓLO una palabra larga (4+ letras) pegada a un número: "habitacion101" → "habitacion 101".
        // No toca unidades ni códigos como "12v", "7A", "N4", "TP0" (evita corromper especificaciones).
        $v = preg_replace('/(\p{L}{4,})(\d)/u', '$1 $2', $v);

        // Corrige palabra por palabra contra el diccionario (respeta el resto tal cual).
        // Sólo cambia si arregla acentos/ortografía; NUNCA sólo mayúsculas (evita ruido masivo) y
        // preserva el estilo: si la palabra venía en MAYÚSCULAS, la corrección también.
        $v = preg_replace_callback('/\p{L}+/u', function ($m) {
            $w = $m[0];
            $fixed = self::TYPOS[$this->deaccent($w)] ?? null;
            if ($fixed === null) {
                return $w;
            }
            if (mb_strtoupper($w) === $w && mb_strtolower($w) !== $w) {
                $fixed = mb_strtoupper($fixed);
            }
            if (mb_strtolower($w) === mb_strtolower($fixed)) {
                return $w; // sólo difería en mayúsculas/minúsculas: no es una corrección real
            }

            return $fixed;
        }, $v);

        $v = trim(preg_replace('/\s+/u', ' ', $v));

        return $v !== $value ? $v : null;
    }

    /** Quita acentos y pasa a minúsculas (para comparar contra el diccionario). */
    private function deaccent(string $s): string
    {
        return strtr(mb_strtolower($s), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
    }

    /** ¿El valor contiene el nombre de un tipo de dispositivo (posible "tipo colado")? */
    private function looksLikeTypeLeak(string $value, array $deviceTypes): bool
    {
        $up = mb_strtoupper($value);
        foreach ($deviceTypes as $t) {
            $t = mb_strtoupper(trim($t));
            if ($t !== '' && mb_strlen($t) >= 4 && str_contains($up, $t)) {
                return true;
            }
        }

        return false;
    }

    // ── Pasada IA (sólo ambiguos, un prompt por lote) ───────────────────────────

    /**
     * Limpia con IA una lista de valores ambiguos. Envía los valores por índice (para no gastar
     * tokens repitiendo texto) y la lista de tipos como contexto. Devuelve [original => {suggested,reason}].
     *
     * @param  array<int,string>  $values
     * @return array<string,array{suggested:string,reason:string}>
     */
    private function aiClean(array $values, string $fieldLabel, array $deviceTypes): array
    {
        $out = [];
        foreach (array_chunk($values, self::AI_BATCH) as $chunk) {
            $out += $this->aiCleanBatch($chunk, $fieldLabel, $deviceTypes);
        }

        return $out;
    }

    private function aiCleanBatch(array $values, string $fieldLabel, array $deviceTypes): array
    {
        $indexed = array_values($values);
        $list = [];
        foreach ($indexed as $i => $v) {
            $list[] = "$i: $v";
        }
        $types = implode(', ', array_slice(array_map('trim', $deviceTypes), 0, 80));

        $system = <<<SYS
        Eres un asistente que limpia el campo "$fieldLabel" de un directorio de dispositivos.
        Recibes una lista de valores (uno por linea, con indice) y la lista de TIPOS DE DISPOSITIVO.
        Para cada valor, corrige SOLO si hace falta:
        - Si el nombre de un TIPO DE DISPOSITIVO se colo dentro de la ubicacion, quitalo y deja solo la ubicacion.
        - Corrige acentos y mayuscula inicial; separa texto y numero (ej. "cuarto101" -> "Cuarto 101").
        No inventes ubicaciones ni agregues datos que no esten en el valor.
        Si el valor ya esta correcto, NO lo incluyas en la respuesta.
        Responde EXCLUSIVAMENTE un JSON: {"c":[{"i":<indice>,"s":"<sugerido>","r":"<motivo corto>"}]}.
        TIPOS DE DISPOSITIVO: $types
        SYS;

        $user = "Valores:\n".implode("\n", $list);

        try {
            $res = $this->provider->chat(
                [['role' => 'user', 'content' => $user]],
                [],
                $system
            );
            $json = $this->extractJson((string) ($res['content'] ?? ''));
            $out = [];
            foreach (($json['c'] ?? []) as $item) {
                $i = $item['i'] ?? null;
                if ($i === null || ! isset($indexed[$i])) {
                    continue;
                }
                $out[$indexed[$i]] = [
                    'suggested' => trim((string) ($item['s'] ?? '')),
                    'reason'    => trim((string) ($item['r'] ?? '')),
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            return []; // a prueba de fallos: si la IA falla, quedan sólo las correcciones deterministas
        }
    }

    /** Extrae el primer objeto JSON de la respuesta del modelo (tolera texto alrededor). */
    private function extractJson(string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }

    // ── Datos del directorio ────────────────────────────────────────────────────

    /** Definiciones de campos del sistema (base + override por cliente), ordenadas. */
    private function fieldDefs(Directory $directory): array
    {
        $clientId = optional($directory->site)->client_id;

        $rows = SystemField::where('catalog_id', $directory->catalog_id)
            ->where('is_active', true)
            ->when($clientId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhere('client_id', $clientId)),
                fn ($q) => $q->whereNull('client_id'))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['client_id', 'field_key', 'label', 'field_type', 'sort_order']);

        return $rows->sortBy(fn ($f) => $f->client_id === null ? 0 : 1)
            ->keyBy('field_key')
            ->sortBy('sort_order')
            ->map(fn ($f) => ['field_key' => $f->field_key, 'label' => $f->label, 'field_type' => $f->field_type])
            ->values()->all();
    }

    /** Valores distintos (no vacíos) de un campo con su número de ocurrencias en el directorio. */
    private function distinctValues(Directory $directory, string $fieldKey): array
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $fieldKey)) {
            return [];
        }

        // La clave está saneada (alfanumérica); se inserta literal para que la expresión de
        // SELECT y la de GROUP BY sean idénticas (Postgres no agrupa por placeholders distintos).
        $expr = "custom_fields->>'{$fieldKey}'";

        return DB::table('devices')
            ->where('directory_id', $directory->id)
            ->whereNull('archived_at')
            ->whereRaw("NULLIF(TRIM({$expr}), '') IS NOT NULL")
            ->selectRaw("{$expr} as val, COUNT(*) as n")
            ->groupByRaw($expr)
            ->pluck('n', 'val')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Vocabulario de tipos de dispositivo del sistema: la columna `device_type` de sus
     * dispositivos + el catálogo de tipos. Sirve para detectar tipos colados en la ubicación.
     *
     * @return array<int,string>
     */
    private function deviceTypeVocabulary(Directory $directory): array
    {
        $fromDevices = DB::table('devices')
            ->where('directory_id', $directory->id)
            ->whereNotNull('device_type')
            ->distinct()->pluck('device_type')->all();

        $fromCatalog = DB::table('catalogs')
            ->where('type', 'device_type')
            ->pluck('label')->all();

        return collect(array_merge($fromDevices, $fromCatalog))
            ->map(fn ($t) => trim((string) $t))
            ->filter()->unique()->values()->all();
    }

    /** Da forma a una fila de sugerencia. */
    private function row(array $field, string $original, string $suggested, string $reason, int $occurrences, string $source): array
    {
        return [
            'id'          => substr(md5($field['key'].'|'.$original), 0, 12),
            'field_key'   => $field['key'],
            'field_label' => $field['label'],
            'original'    => $original,
            'suggested'   => $suggested,
            'reason'      => $reason,
            'occurrences' => $occurrences,
            'source'      => $source, // 'auto' | 'ai'
        ];
    }
}
