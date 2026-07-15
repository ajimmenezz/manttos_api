<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDocumentChunk extends Model
{
    protected $fillable = [
        'ai_document_id', 'idx', 'heading', 'content', 'embedding', 'embedding_model',
        'collection', 'catalog_id', 'client_id', 'audience',
    ];

    protected $casts = ['embedding' => 'array'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(AiDocument::class, 'ai_document_id');
    }
}
