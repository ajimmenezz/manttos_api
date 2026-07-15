<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hilo de captación por contacto. Persistente: NO se cierra al generar un evento;
 * puede acumular varios eventos (cada uno marcado con un mensaje 'system').
 * `handling` = ai|human (si un humano tomó la conversación, la IA calla).
 * `context_summary` = memoria acumulada del agente para no re-preguntar.
 */
class CaptureConversation extends Model
{
    protected $fillable = [
        'channel_id', 'contact_id', 'status', 'handling', 'assigned_agent_id',
        'state', 'context_summary', 'unread_count', 'event_id',
        'last_message_at', 'last_inbound_at', 'is_simulation',
    ];

    protected function casts(): array
    {
        return [
            'state'           => 'array',
            'unread_count'    => 'integer',
            'is_simulation'   => 'boolean',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
        ];
    }

    public function isHumanHandled(): bool { return $this->handling === 'human'; }

    public function channel()       { return $this->belongsTo(Channel::class); }
    public function contact()       { return $this->belongsTo(CaptureContact::class, 'contact_id'); }
    public function event()         { return $this->belongsTo(Event::class); }
    public function assignedAgent() { return $this->belongsTo(User::class, 'assigned_agent_id'); }
    public function messages()      { return $this->hasMany(CaptureMessage::class, 'conversation_id')->orderBy('created_at'); }
}
