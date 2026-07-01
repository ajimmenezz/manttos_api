<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventStatus extends Model
{
    protected $fillable = [
        'key', 'label', 'color', 'category', 'category_id',
        'is_initial', 'is_terminal', 'requires_form', 'requires_note', 'sort_order', 'is_active',
    ];

    public function category()
    {
        return $this->belongsTo(Catalog::class, 'category_id');
    }

    protected function casts(): array
    {
        return [
            'is_initial'    => 'boolean',
            'is_terminal'   => 'boolean',
            'requires_form' => 'boolean',
            'requires_note' => 'boolean',
            'is_active'     => 'boolean',
            'sort_order'    => 'integer',
        ];
    }

    /** Estados a los que se puede pasar según el flujo GENERAL. */
    public function allowedNext()
    {
        return $this->belongsToMany(
            EventStatus::class,
            'event_status_transitions',
            'from_status_id',
            'to_status_id'
        );
    }
}
