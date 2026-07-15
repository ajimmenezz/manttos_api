<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Línea de mensajería para captación de eventos (Telegram/WhatsApp), dedicada a
 * un cliente. El token vive cifrado. `messagingProvider()` (no `provider()`, que
 * chocaría con la columna) resuelve el proveedor.
 */
class Channel extends Model
{
    public const PROVIDER_TELEGRAM = 'telegram';
    public const PROVIDER_WHATSAPP = 'whatsapp';
    public const PROVIDER_INAPP    = 'inapp';    // chat dentro de la app (usuario autenticado)

    protected $fillable = [
        'name', 'provider', 'client_id', 'access_token', 'phone_number_id',
        'default_event_type_id', 'default_system_id', 'created_by_user_id',
        'agent_name', 'instructions', 'ai_enabled', 'require_registered', 'is_active', 'metadata',
        'first_level_support',
    ];

    public const SUPPORT_OFF     = 'off';     // solo capta (comportamiento base)
    public const SUPPORT_ASSIST  = 'assist';  // crea el ticket + da tips del manual
    public const SUPPORT_DEFLECT  = 'deflect'; // intenta resolver con el manual y escala si no funciona

    public function supportMode(): string
    {
        $m = $this->first_level_support ?: self::SUPPORT_OFF;
        return in_array($m, [self::SUPPORT_ASSIST, self::SUPPORT_DEFLECT], true) ? $m : self::SUPPORT_OFF;
    }

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'metadata'           => 'array',
            'ai_enabled'         => 'boolean',
            'require_registered' => 'boolean',
            'is_active'          => 'boolean',
        ];
    }

    protected $appends = ['has_token'];

    public function getHasTokenAttribute(): bool
    {
        return ! empty($this->access_token);
    }

    public function messagingProvider(): string
    {
        return $this->provider ?: self::PROVIDER_TELEGRAM;
    }

    public function isTelegram(): bool { return $this->messagingProvider() === self::PROVIDER_TELEGRAM; }
    public function isWhatsApp(): bool { return $this->messagingProvider() === self::PROVIDER_WHATSAPP; }
    public function isInApp(): bool    { return $this->messagingProvider() === self::PROVIDER_INAPP; }

    /** Token del proveedor (bot token / WA token), descifrado. */
    public function token(): ?string
    {
        return $this->access_token ?: null;
    }

    public function client()      { return $this->belongsTo(Client::class); }
    public function defaultType() { return $this->belongsTo(EventType::class, 'default_event_type_id'); }
    public function defaultSystem(){ return $this->belongsTo(Catalog::class, 'default_system_id'); }
    public function createdBy()   { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function conversations(){ return $this->hasMany(CaptureConversation::class); }
}
