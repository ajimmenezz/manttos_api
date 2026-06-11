<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceFieldValue extends Model
{
    protected $fillable = [
        'device_id',
        'system_field_id',
        'field_key',
        'value_text',
        'value_number',
        'value_date',
        'value_boolean',
    ];

    protected function casts(): array
    {
        return [
            'value_number'  => 'decimal:6',
            'value_boolean' => 'boolean',
            'value_date'    => 'date:Y-m-d',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function systemField(): BelongsTo
    {
        return $this->belongsTo(SystemField::class);
    }

    /** Devuelve el valor en su tipo nativo según qué columna está rellena */
    public function getTypedValueAttribute(): mixed
    {
        if (! is_null($this->value_boolean)) return $this->value_boolean;
        if (! is_null($this->value_date))    return $this->value_date;
        if (! is_null($this->value_number))  return $this->value_number;
        return $this->value_text;
    }
}
