<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiInteraction extends Model
{
    protected $fillable = [
        'conversation_id', 'source', 'user_id', 'prompt', 'reply', 'provider', 'model',
        'input_tokens', 'output_tokens', 'cost_usd', 'price_in', 'price_out',
        'duration_ms', 'iterations', 'actions', 'status', 'error',
    ];

    protected $casts = [
        'actions'       => 'array',
        'cost_usd'      => 'decimal:6',
        'price_in'      => 'decimal:4',
        'price_out'     => 'decimal:4',
        'input_tokens'  => 'integer',
        'output_tokens' => 'integer',
        'duration_ms'   => 'integer',
        'iterations'    => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class);
    }
}
