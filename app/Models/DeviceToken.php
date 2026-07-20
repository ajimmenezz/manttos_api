<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token de push de un dispositivo. Ver la migración para por qué el upsert va por
 * `token` y no por usuario (teléfonos compartidos).
 */
class DeviceToken extends Model
{
    public const PLATFORMS = ['android', 'ios', 'web'];

    protected $fillable = [
        'user_id', 'token', 'platform', 'provider', 'app_version', 'device_name', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registra o reasigna un token. Si el mismo aparato lo trae con otro usuario,
     * el token cambia de dueño en vez de duplicarse.
     */
    public static function register(User $user, string $token, string $platform, array $extra = []): self
    {
        return static::updateOrCreate(
            ['token' => $token],
            array_merge([
                'user_id'      => $user->id,
                'platform'     => $platform,
                'provider'     => $extra['provider'] ?? 'fcm',
                'last_seen_at' => now(),
            ], array_intersect_key($extra, array_flip(['app_version', 'device_name'])))
        );
    }
}
