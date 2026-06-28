<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'short_name',
        'rfc',
        'industry',
        'contact_name',
        'contact_email',
        'contact_phone',
        'is_active',
        'notes',
        'created_by',
        'event_folio_config',
    ];

    protected function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'event_folio_config' => 'array',
        ];
    }

    public function sites()
    {
        return $this->hasMany(Site::class);
    }

    public function admins()
    {
        return $this->belongsToMany(User::class, 'client_user')->withTimestamps();
    }

    /** Ingenieros que pueden atender a este cliente (→ todos sus sitios). */
    public function engineers()
    {
        return $this->belongsToMany(User::class, 'client_engineers')->withTimestamps();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
