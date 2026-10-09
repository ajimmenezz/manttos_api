<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Carpeta del módulo Almacenamiento. Ver la migración `create_storage_tables`. */
class StorageFolder extends Model
{
    public const KINDS_ANCHOR = ['client', 'site'];

    protected $fillable = [
        'parent_id', 'kind', 'system_key', 'name', 'drive_id', 'path',
        'client_id', 'site_id', 'owner_id', 'created_by',
        'trashed_at', 'trashed_by', 'trash_root_type', 'trash_root_id',
    ];

    protected function casts(): array
    {
        return ['trashed_at' => 'datetime'];
    }

    public function parent()   { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function files()    { return $this->hasMany(StorageFile::class, 'folder_id'); }
    public function client()   { return $this->belongsTo(Client::class)->withTrashed(); }
    public function site()     { return $this->belongsTo(Site::class)->withTrashed(); }
    public function owner()    { return $this->belongsTo(User::class, 'owner_id'); }
    public function creator()  { return $this->belongsTo(User::class, 'created_by'); }

    /** Ids de los ancestros, de la raíz hacia abajo, sin incluirse. */
    public function ancestorIds(): array
    {
        $ids = array_map('intval', array_filter(explode('/', $this->path)));
        array_pop($ids);
        return $ids;
    }

    /** Las carpetas de cliente y sitio siguen el nombre de su entidad: no se renombran ni se mueven. */
    public function isAnchor(): bool
    {
        return in_array($this->kind, self::KINDS_ANCHOR, true) || $this->kind === 'system';
    }

    public function scopeAlive($q) { return $q->whereNull('trashed_at'); }
}
