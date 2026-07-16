<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Regla de comportamiento del agente de captación (creada por un superadmin) que se
 * inyecta en el system prompt de [[CaptureAgent]]. Alcance: 'global' (todas las líneas),
 * 'channel' (una línea) o 'system' (un sistema/catalog). Ver [[project-mantenimientos-knowledge-rag]].
 */
class CaptureAgentRule extends Model
{
    public const SCOPE_GLOBAL  = 'global';
    public const SCOPE_CHANNEL = 'channel';
    public const SCOPE_SYSTEM  = 'system';

    protected $fillable = [
        'scope', 'channel_id', 'catalog_id', 'title', 'instruction',
        'example_bad', 'example_good', 'is_active', 'sort_order',
        'created_by', 'source_conversation_id', 'source_context',
    ];

    protected function casts(): array
    {
        return [
            'is_active'      => 'boolean',
            'sort_order'     => 'integer',
            'source_context' => 'array',
        ];
    }

    public function channel() { return $this->belongsTo(Channel::class); }
    public function system()  { return $this->belongsTo(Catalog::class, 'catalog_id'); }
    public function author()  { return $this->belongsTo(User::class, 'created_by'); }
}
