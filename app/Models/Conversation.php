<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Conversación del chat interno (usuarios de la plataforma), 1-a-1 o grupo.
 * No confundir con CaptureConversation (hilo de captación por WhatsApp/Telegram).
 *
 * `client_id` fija el alcance multi-cliente: null = conversación interna; si hay un
 * usuario de cliente, queda amarrada a ese cliente y nadie de otro entra (ver ChatScope).
 * `direct_key` = "menorId:mayorId" y es única, para no duplicar el 1-a-1.
 */
class Conversation extends Model
{
    use SoftDeletes;

    public const TYPES = ['direct', 'group'];

    protected $fillable = [
        'type', 'name', 'avatar_url', 'created_by', 'client_id',
        'direct_key', 'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function isDirect(): bool { return $this->type === 'direct'; }
    public function isGroup(): bool  { return $this->type === 'group'; }

    /** Llave canónica de una conversación directa entre dos usuarios. */
    public static function directKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function client()       { return $this->belongsTo(Client::class); }
    public function participants() { return $this->hasMany(ConversationParticipant::class); }
    public function messages()     { return $this->hasMany(Message::class); }
    public function links()        { return $this->hasMany(ConversationLink::class); }

    /** Usuarios participantes ACTIVOS (left_at null). */
    public function activeUsers()
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'joined_at', 'left_at', 'last_read_message_id', 'muted_until'])
            ->wherePivotNull('left_at');
    }

    /** Participación (activa) de un usuario, o null si no pertenece / ya salió. */
    public function participantFor(int $userId): ?ConversationParticipant
    {
        return $this->participants()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();
    }

    public function hasActiveParticipant(int $userId): bool
    {
        return $this->participants()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }
}
