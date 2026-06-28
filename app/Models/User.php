<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'is_active',
        'created_by',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password'             => 'hashed',
            'must_change_password' => 'boolean',
            'is_active'            => 'boolean',
            'last_login_at'        => 'datetime',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clientsAsAdmin()
    {
        return $this->belongsToMany(Client::class, 'client_user')->withTimestamps();
    }

    public function sitesAsAdmin()
    {
        return $this->belongsToMany(Site::class, 'site_user')->withTimestamps();
    }

    /** Clientes que este ingeniero puede atender (→ todos sus sitios). */
    public function clientsAsEngineer()
    {
        return $this->belongsToMany(Client::class, 'client_engineers')->withTimestamps();
    }

    /** Sitios específicos que este ingeniero puede atender. */
    public function sitesAsEngineer()
    {
        return $this->belongsToMany(Site::class, 'site_engineers')->withTimestamps();
    }

    /**
     * Pueden emitir llaves de API todos los roles por debajo de admin
     * (admin-cliente, admin-sitio, ingeniero, técnico). Nunca superadmin ni admin:
     * el acceso por API jamás opera a nivel administrativo global.
     */
    public function canManageApiTokens(): bool
    {
        return ! $this->hasAnyRole(['superadmin', 'admin']);
    }
}
