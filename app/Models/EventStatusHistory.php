<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventStatusHistory extends Model
{
    protected $table = 'event_status_history';
    public $timestamps = false; // sólo created_at

    protected $fillable = [
        'event_id', 'from_status_id', 'to_status_id', 'user_id', 'note', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function event()      { return $this->belongsTo(Event::class); }
    public function fromStatus() { return $this->belongsTo(EventStatus::class, 'from_status_id'); }
    public function toStatus()   { return $this->belongsTo(EventStatus::class, 'to_status_id'); }
    public function user()       { return $this->belongsTo(User::class, 'user_id'); }
}
