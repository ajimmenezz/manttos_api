<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Archivo del módulo Almacenamiento: los bytes viven en Google Drive (`drive_id`). */
class StorageFile extends Model
{
    protected $fillable = [
        'folder_id', 'name', 'mime_type', 'size', 'drive_id', 'status', 'md5', 'sha256', 'upload_url',
        'client_id', 'site_id', 'owner_id', 'path',
        'uploaded_by', 'uploaded_at', 'trashed_at', 'trashed_by', 'trash_root_type', 'trash_root_id',
    ];

    protected $hidden = ['upload_url', 'drive_id'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime', 'trashed_at' => 'datetime', 'size' => 'integer'];
    }

    public function folder()   { return $this->belongsTo(StorageFolder::class, 'folder_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function scopeAlive($q) { return $q->whereNull('trashed_at'); }
    public function scopeReady($q) { return $q->where('status', 'ready'); }
}
