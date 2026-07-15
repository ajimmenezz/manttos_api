<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFeedback extends Model
{
    // "feedback" es incontable para el pluralizador de Laravel → forzamos el nombre.
    protected $table = 'ai_feedbacks';

    protected $fillable = ['ai_interaction_id', 'user_id', 'rating', 'comment'];

    public function interaction(): BelongsTo
    {
        return $this->belongsTo(AiInteraction::class, 'ai_interaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
