<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un error reportado por la app móvil. Ver la migración para el porqué del modelo.
 */
class AppErrorLog extends Model
{
    /** Registros inmutables salvo el cierre; sin updated_at. */
    public const UPDATED_AT = null;

    public const KINDS = ['crash', 'api', 'upload', 'handled'];

    protected $fillable = [
        'user_id', 'device_id', 'device_name', 'device_model', 'platform', 'os_version',
        'app_version', 'build', 'kind', 'context', 'message', 'detail',
        'http_method', 'http_url', 'http_status', 'fingerprint', 'occurred_at', 'created_at',
        'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at'  => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    /**
     * Huella para agrupar repeticiones del mismo error. Se normaliza el mensaje
     * quitando lo que cambia en cada ocurrencia —números, UUID, URLs y comillas—
     * para que "evento 412 rechazado" y "evento 907 rechazado" caigan en el mismo
     * grupo en vez de inundar la lista con una fila por ocurrencia.
     */
    public static function fingerprintFor(string $kind, ?string $context, string $message, ?int $status = null): string
    {
        $normalized = mb_strtolower($message);
        $normalized = preg_replace('/https?:\/\/\S+/', '{url}', $normalized);
        $normalized = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '{uuid}', $normalized);
        $normalized = preg_replace('/\d+/', '{n}', $normalized);
        $normalized = preg_replace('/["\'“”]/u', '', $normalized);
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized));

        return hash('sha256', implode('|', [$kind, $context ?? '', $status ?? '', mb_substr($normalized, 0, 200)]));
    }
}
