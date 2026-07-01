<?php

namespace App\Traits;

use App\Models\Catalog;
use App\Models\Device;
use App\Models\Directory;
use App\Models\SystemField;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait ManagesDeviceData
{
    /**
     * Resuelve los campos DID de un dispositivo dentro de sus custom_fields.
     * Port del frontend `app/src/lib/did.ts` (resolveDidValue/buildDidPattern) para
     * que la importación masiva compute el DID igual que el formulario.
     *
     * @param array<string,mixed> $customFields  valores capturados (se muta y devuelve)
     * @param \Illuminate\Support\Collection<int,SystemField> $fields  campos activos del directorio
     * @return array<string,mixed>
     */
    protected function resolveDidCustomFields(array $customFields, $fields): array
    {
        $didFields = $fields->where('field_type', 'did');
        if ($didFields->isEmpty()) return $customFields;

        // Nomenclatura del tipo de dispositivo seleccionado (campo lista catalog_type=device_type).
        $nomenclatura = '';
        $deviceTypeField = $fields->first(
            fn ($f) => $f->field_type === 'list' && $f->catalog_type === 'device_type'
        );
        if ($deviceTypeField) {
            $label = $customFields[$deviceTypeField->field_key] ?? null;
            if (is_string($label) && trim($label) !== '') {
                $nomenclatura = (string) (Catalog::where('type', 'device_type')
                    ->where('label', $label)
                    ->value('nomenclatura') ?? '');
            }
        }

        foreach ($didFields as $field) {
            $config = $field->config ?? [];
            $mode   = $config['did_mode'] ?? 'text';
            $raw    = $customFields[$field->field_key] ?? null;

            $customFields[$field->field_key] = match ($mode) {
                'pattern' => $this->buildDidPattern($config['pattern'] ?? [], $customFields, $nomenclatura),
                'number'  => $this->padDidNumber($raw, $config['pad_length'] ?? null),
                default   => $raw === null ? '' : (string) $raw,
            };
        }

        return $customFields;
    }

    /** Rellena con ceros a la izquierda (solo si el valor es numérico). */
    private function padDidNumber(mixed $value, mixed $len): string
    {
        $s = $value === null ? '' : (string) $value;
        $len = (int) $len;
        if ($len <= 0 || $s === '' || ! preg_match('/^\d+$/', $s)) return $s;
        return str_pad($s, $len, '0', STR_PAD_LEFT);
    }

    /** Construye el DID en modo patrón a partir de las piezas (tokens). */
    private function buildDidPattern(mixed $tokens, array $values, string $nomenclatura): string
    {
        if (! is_array($tokens) || empty($tokens)) return '';

        $applyFormat = function (mixed $raw, mixed $pad, mixed $upper): string {
            $s = $raw === null ? '' : (string) $raw;
            $pad = (int) $pad;
            if ($pad > 0 && preg_match('/^\d+$/', $s)) $s = str_pad($s, $pad, '0', STR_PAD_LEFT);
            if ($upper) $s = mb_strtoupper($s);
            return $s;
        };

        $out = '';
        foreach ($tokens as $t) {
            $kind = $t['kind'] ?? 'const';
            if ($kind === 'const') {
                $out .= $t['text'] ?? '';
            } elseif ($kind === 'nomenclatura') {
                $out .= $applyFormat($nomenclatura, $t['pad'] ?? null, $t['uppercase'] ?? false);
            } else { // 'field'
                $out .= $applyFormat($values[$t['field_key'] ?? ''] ?? null, $t['pad'] ?? null, $t['uppercase'] ?? false);
            }
        }
        return $out;
    }

    protected function syncFieldValues(Device $device, Directory $directory, array $customFields): void
    {
        if (empty($customFields)) {
            DB::table('device_field_values')->where('device_id', $device->id)->delete();
            return;
        }

        // Campos base + campos extra del cliente (si el directorio pertenece a uno)
        $clientId = $directory->site->client_id ?? null;

        $systemFields = SystemField::where('catalog_id', $directory->catalog_id)
            ->where(function ($q) use ($clientId) {
                $q->whereNull('client_id');
                if ($clientId) $q->orWhere('client_id', $clientId);
            })
            ->where('is_active', true)
            ->get()
            ->keyBy('field_key');

        $rows = [];

        foreach ($customFields as $key => $rawValue) {
            /** @var SystemField|null $field */
            $field = $systemFields->get($key);

            if (! $field) continue;
            if (is_null($rawValue) || $rawValue === '') continue;

            $row = [
                'device_id'       => $device->id,
                'system_field_id' => $field->id,
                'field_key'       => $key,
                'value_text'      => null,
                'value_number'    => null,
                'value_date'      => null,
                'value_boolean'   => null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];

            switch ($field->field_type) {
                case 'text':
                case 'list':
                    $row['value_text'] = (string) $rawValue;
                    break;

                case 'number':
                    if (is_numeric($rawValue)) {
                        $row['value_number'] = (float) $rawValue;
                    }
                    break;

                case 'date':
                    try {
                        $row['value_date'] = Carbon::parse($rawValue)->toDateString();
                    } catch (\Throwable) {}
                    break;

                case 'boolean':
                    $row['value_boolean'] = filter_var($rawValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    break;
            }

            $rows[] = $row;
        }

        DB::table('device_field_values')->where('device_id', $device->id)->delete();

        if (! empty($rows)) {
            DB::table('device_field_values')->insert($rows);
        }
    }

    protected function deriveDisplayName(array $customFields, Directory $directory): string
    {
        foreach ($customFields as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $count = $directory->devices()->count() + 1;
        return "Dispositivo #{$count}";
    }

    protected function deriveDeviceType(array $customFields): ?string
    {
        foreach ($customFields as $key => $value) {
            if (is_string($value) && str_contains(strtolower($key), 'tipo')) {
                return $value;
            }
        }
        return null;
    }
}
