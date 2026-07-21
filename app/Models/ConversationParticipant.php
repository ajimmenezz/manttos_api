<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Membresía de un usuario en una conversación. `left_at` null = activo.
 * `last_read_message_id` es la marca de agua de lectura (no-leídos = mensajes con id mayor).
 */
class ConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id', 'user_id', 'role', 'joined_at', 'left_at',
        'last_read_message_id', 'cleared_before_message_id', 'muted_until',
    ];

    protected function casts(): array
    {
        return [
            'joined_at'                 => 'datetime',
            'left_at'                   => 'datetime',
            'muted_until'               => 'datetime',
            'last_read_message_id'      => 'integer',
            'cleared_before_message_id' => 'integer',
        ];
    }

    public function isAdmin(): bool  { return $this->role === 'admin'; }
    public function isActive(): bool { return $this->left_at === null; }

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function user()         { return $this->belongsTo(User::class); }
}
