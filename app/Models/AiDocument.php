<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiDocument extends Model
{
    public const COLLECTION_ASSISTANT = 'assistant'; // manual/guías del asistente interno
    public const COLLECTION_SUPPORT    = 'support';    // base de conocimiento por sistema

    public const AUDIENCE_SUPPORT  = 'support';   // el agente puede guiar al usuario con esto
    public const AUDIENCE_INTERNAL = 'internal';  // solo moldea el tono/criterio (no se cita textual)

    protected $fillable = [
        'title', 'source', 'kind', 'chunks_count',
        'collection', 'catalog_id', 'client_id', 'audience', 'body_md',
        'original_filename', 'file_path', 'status', 'error',
        'is_active', 'structured', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'structured' => 'boolean',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(AiDocumentChunk::class);
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(Catalog::class, 'catalog_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
