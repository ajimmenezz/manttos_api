<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Adjunto de un mensaje. La URL ya viene de POST /media/upload (hoy solo imágenes,
 * máx 10 MB); `kind` deja preparado 'file' y 'video' sin migrar de nuevo.
 */
class MessageAttachment extends Model
{
    public const KINDS = ['image', 'file', 'video'];

    protected $fillable = [
        'message_id', 'url', 'mime', 'size', 'kind', 'width', 'height', 'thumb_url',
    ];

    protected function casts(): array
    {
        return [
            'size'   => 'integer',
            'width'  => 'integer',
            'height' => 'integer',
        ];
    }

    public function message() { return $this->belongsTo(Message::class); }
}
