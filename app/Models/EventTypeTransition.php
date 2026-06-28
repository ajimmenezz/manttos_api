<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventTypeTransition extends Model
{
    protected $fillable = ['event_type_id', 'from_status_id', 'to_status_id'];

    public function fromStatus()
    {
        return $this->belongsTo(EventStatus::class, 'from_status_id');
    }

    public function toStatus()
    {
        return $this->belongsTo(EventStatus::class, 'to_status_id');
    }
}
