<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Mensaje del chat interno. El borrado es SOFT: el cliente sigue viendo el hueco
 * ("mensaje eliminado") y los ids no se reciclan, lo que mantiene coherente la
 * marca de agua `last_read_message_id`.
 */
class Message extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'conversation_id', 'sender_id', 'body', 'reply_to_id',
        'client_uuid', 'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function sender()       { return $this->belongsTo(User::class, 'sender_id'); }
    public function replyTo()      { return $this->belongsTo(Message::class, 'reply_to_id'); }
    public function attachments()  { return $this->hasMany(MessageAttachment::class); }
    public function reads()        { return $this->hasMany(MessageRead::class); }
}
