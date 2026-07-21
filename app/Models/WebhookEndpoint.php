<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Suscripción a webhook de un cliente (opcionalmente acotada a un sitio). `events` nulo
 * o vacío = todos los del catálogo. `secret` firma cada entrega (HMAC-SHA256).
 */
class WebhookEndpoint extends Model
{
    protected $fillable = [
        'client_id', 'site_id', 'url', 'secret', 'description',
        'events', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'events'          => 'array',
            'is_active'       => 'boolean',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    protected $hidden = ['secret'];   // no se expone salvo al crear/regenerar

    public function client()     { return $this->belongsTo(Client::class); }
    public function site()       { return $this->belongsTo(Site::class); }
    public function creator()    { return $this->belongsTo(User::class, 'created_by'); }
    public function deliveries() { return $this->hasMany(WebhookDelivery::class); }

    /** ¿Este endpoint está suscrito al tipo de evento dado? (vacío = todos). */
    public function subscribedTo(string $eventType): bool
    {
        return empty($this->events) || in_array($eventType, $this->events, true);
    }

    /** Genera un secreto nuevo para la firma. */
    public static function freshSecret(): string
    {
        return 'whsec_' . Str::random(48);
    }
}
