<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mensaje de una conversación de captación.
 * direction: in (contacto) | out (agente IA) | human (humano desde la bandeja) |
 * system (checkpoint, p. ej. "evento creado"; no se envía por el canal).
 */
class CaptureMessage extends Model
{
    public $timestamps = false; // solo created_at

    protected $fillable = [
        'conversation_id', 'channel_id', 'direction', 'sender_user_id',
        'external_message_id', 'body', 'payload', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function conversation() { return $this->belongsTo(CaptureConversation::class, 'conversation_id'); }
    public function sender()       { return $this->belongsTo(User::class, 'sender_user_id'); }
}
