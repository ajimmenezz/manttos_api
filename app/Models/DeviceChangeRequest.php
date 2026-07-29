<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceChangeRequest extends Model
{
    protected $fillable = [
        'device_id', 'event_id', 'requested_by', 'changes', 'note',
        'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'changes'     => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
