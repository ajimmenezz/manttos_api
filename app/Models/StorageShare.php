<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una carpeta o archivo compartido con un usuario o un rol. Se hereda hacia abajo. */
class StorageShare extends Model
{
    public const LEVELS = ['view' => 1, 'upload' => 2, 'manage' => 3];

    protected $fillable = ['item_type', 'item_id', 'grantee_type', 'grantee_id', 'level', 'created_by'];
}
