<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Liga entre una conversación y un objeto de operación (evento / mantenimiento / sitio).
 * La tabla se crea en fase 1 para no volver a migrar; su API (ligar y crear evento
 * desde el chat) es la FASE 3 del spec.
 */
class ConversationLink extends Model
{
    public const TYPES = ['event', 'maintenance', 'site'];

    protected $fillable = ['conversation_id', 'linkable_type', 'linkable_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'linkable_id' => 'integer',
        ];
    }

    public function conversation() { return $this->belongsTo(Conversation::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }

    /** Resuelve el objeto ligado (enum corto controlado, no morphTo). */
    public function linkable()
    {
        return match ($this->linkable_type) {
            'event'       => Event::find($this->linkable_id),
            'maintenance' => Maintenance::find($this->linkable_id),
            'site'        => Site::find($this->linkable_id),
            default       => null,
        };
    }
}
