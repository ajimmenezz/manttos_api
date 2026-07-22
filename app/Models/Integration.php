<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Configuración de una integración externa (Odoo, Jira, …) para un alcance:
 * client_id NULL = global de la plataforma; con client_id = override de ese cliente.
 *
 * `config` se guarda CIFRADO (credenciales, URLs, mapeos). Nunca se expone en crudo;
 * el controlador enmascara los campos marcados como secretos.
 */
class Integration extends Model
{
    protected $fillable = [
        'provider', 'client_id', 'is_active', 'config', 'inbound_secret',
        'last_ok_at', 'last_error_at', 'last_error', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'config'       => 'encrypted:array',
            'is_active'    => 'boolean',
            'last_ok_at'   => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    protected $hidden = ['config', 'inbound_secret'];

    public function client()  { return $this->belongsTo(Client::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function logs()    { return $this->hasMany(IntegrationLog::class); }

    /** Valor de config con default seguro. */
    public function conf(string $key, $default = null)
    {
        return data_get($this->config, $key, $default);
    }

    /** ¿Es la configuración global (aplica a todos los clientes sin override)? */
    public function isGlobal(): bool
    {
        return $this->client_id === null;
    }

    /** Genera un secreto para verificar las llamadas entrantes. */
    public static function freshInboundSecret(): string
    {
        return 'inb_' . Str::random(48);
    }
}
