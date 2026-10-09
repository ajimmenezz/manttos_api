<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bitácora de Almacenamiento: el autor REAL de cada acción (en Drive todo es la cuenta de servicio). */
class StorageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'item_type', 'item_id', 'item_name', 'meta', 'ip', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
}
