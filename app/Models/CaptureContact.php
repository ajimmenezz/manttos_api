<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Remitente que escribe por una línea de captación (chat_id/wa_id por canal).
 * Puede etiquetarse a un cliente/sitio (client_id/site_id) para que el agente no
 * pregunte de dónde escribe, o pre-registrarse desde la ficha del cliente/sitio.
 */
class CaptureContact extends Model
{
    protected $fillable = [
        'channel_id', 'external_id', 'name', 'client_id', 'site_id', 'user_id', 'username', 'pre_registered',
    ];

    protected function casts(): array
    {
        return ['pre_registered' => 'boolean'];
    }

    public function channel()       { return $this->belongsTo(Channel::class); }
    public function client()        { return $this->belongsTo(Client::class); }
    public function site()          { return $this->belongsTo(Site::class); }
    public function user()          { return $this->belongsTo(User::class); }
    public function conversations() { return $this->hasMany(CaptureConversation::class, 'contact_id'); }
}
