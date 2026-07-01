<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSlaTier extends Model
{
    protected $fillable = ['key', 'label', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function statuses()
    {
        return $this->hasMany(EventStatus::class, 'sla_tier_id');
    }
}
