<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bitácora de un intento de entrega de webhook (estado, respuesta, reintentos). */
class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_endpoint_id', 'event_type', 'payload', 'status',
        'attempts', 'response_status', 'response_body', 'error', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'delivered_at' => 'datetime',
        ];
    }

    public function endpoint() { return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id'); }
}
