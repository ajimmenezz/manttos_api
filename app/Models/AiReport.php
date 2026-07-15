<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiReport extends Model
{
    protected $fillable = ['user_id', 'conversation_id', 'title', 'html'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
