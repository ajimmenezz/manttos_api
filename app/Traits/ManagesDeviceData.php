<?php

namespace App\Traits;

use App\Models\Device;
use App\Models\Directory;
use App\Models\SystemField;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait ManagesDeviceData
{
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
