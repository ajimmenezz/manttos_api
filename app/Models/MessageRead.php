<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Acuse por usuario y mensaje (entregado / leído), para las palomitas.
 * El conteo de no-leídos NO se calcula aquí sino con la marca de agua
 * `conversation_participants.last_read_message_id` (mucho más barato).
 */
class MessageRead extends Model
{
    protected $fillable = ['message_id', 'user_id', 'delivered_at', 'read_at'];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at'      => 'datetime',
        ];
    }

    public function message() { return $this->belongsTo(Message::class); }
    public function user()    { return $this->belongsTo(User::class); }
}
