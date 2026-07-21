<?php

namespace App\Models;

use App\Support\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'type', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * De un conjunto de usuarios, cuáles tienen APAGADO un tipo (fila explícita
     * enabled=false). Los demás cuentan como encendidos por ausencia (default).
     *
     * @param  iterable<int>  $userIds
     * @return Collection<int,int>  ids de usuario que optaron por NO recibir este tipo
     */
    public static function optedOut(iterable $userIds, string $type): Collection
    {
        // Si el propio default del tipo es "apagado", la ausencia significa apagado:
        // aquí solo se listan las excepciones explícitas, por lo que este helper asume
        // defaults "encendido" (los del catálogo actual). Se deja explícito por si cambia.
        return static::whereIn('user_id', $userIds)
            ->where('type', $type)
            ->where('enabled', false)
            ->pluck('user_id');
    }

    /** Preferencias efectivas del usuario: catálogo con el valor real (o su default). */
    public static function forUser(int $userId): array
    {
        $saved = static::where('user_id', $userId)->pluck('enabled', 'type');

        return array_map(function (array $entry) use ($saved) {
            $entry['enabled'] = $saved->has($entry['type'])
                ? (bool) $saved[$entry['type']]
                : $entry['default'];

            return $entry;
        }, NotificationType::catalog());
    }
}
