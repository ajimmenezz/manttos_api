<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora de una interacción con un sistema externo: salida (nuestro evento → externo),
 * entrada (llamada entrante) o consulta (p. ej. inventario). Es puramente informativa;
 * nada del negocio depende de ella.
 */
class IntegrationLog extends Model
{
    protected $fillable = [
        'integration_id', 'provider', 'client_id', 'direction', 'event_type',
        'status', 'attempts', 'payload', 'response', 'error', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'response'     => 'array',
            'delivered_at' => 'datetime',
        ];
    }

    public function integration() { return $this->belongsTo(Integration::class); }
}
