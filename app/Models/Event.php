<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    public const PRIORITIES = ['baja', 'media', 'alta', 'critica'];
    public const IMPACTS    = ['alto', 'medio', 'bajo'];
    public const URGENCIES  = ['alta', 'media', 'baja'];

    protected $fillable = [
        'folio',
        'client_uuid',
        'client_id',
        'site_id',
        'system_id',
        'event_type_id',
        'device_id',
        'status_id',
        'priority',
        'impact',
        'urgency',
        'priority_auto',
        'scheduled_attention_at',
        'description',
        'field_values',
        'created_by',
        'assigned_to',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'field_values'           => 'array',
            'occurred_at'            => 'datetime',
            'scheduled_attention_at' => 'datetime',
            'priority_auto'          => 'boolean',
        ];
    }

    public function client()    { return $this->belongsTo(Client::class); }
    public function site()      { return $this->belongsTo(Site::class); }
    public function system()    { return $this->belongsTo(Catalog::class, 'system_id'); }
    public function eventType() { return $this->belongsTo(EventType::class, 'event_type_id'); }
    public function device()    { return $this->belongsTo(Device::class, 'device_id'); }
    public function status()    { return $this->belongsTo(EventStatus::class, 'status_id'); }
    public function creator()   { return $this->belongsTo(User::class, 'created_by'); }
    public function assignee()  { return $this->belongsTo(User::class, 'assigned_to'); }

    public function history()
    {
        return $this->hasMany(EventStatusHistory::class)->orderBy('created_at');
    }
}
