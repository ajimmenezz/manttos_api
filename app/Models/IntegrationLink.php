<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo entre un recurso local (evento) y su contraparte externa (issue de Jira, etc.).
 */
class IntegrationLink extends Model
{
    protected $fillable = [
        'integration_id', 'provider', 'local_type', 'local_id',
        'external_key', 'external_id', 'external_url', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function integration() { return $this->belongsTo(Integration::class); }
}
