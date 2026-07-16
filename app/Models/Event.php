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
        'images',
        'ai_diagnosis',
        'ai_diagnosis_at',
        'ai_summary',
        'ai_summary_at',
        'ai_summary_stale',
        'created_by',
        'assigned_to',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'field_values'           => 'array',
            'images'                 => 'array',
            'ai_diagnosis'           => 'array',
            'ai_diagnosis_at'        => 'datetime',
            'ai_summary_at'          => 'datetime',
            'ai_summary_stale'       => 'boolean',
            'occurred_at'            => 'datetime',
            'scheduled_attention_at' => 'datetime',
            'priority_auto'          => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Cualquier cambio de fondo del evento marca el Resumen de IA como
        // DESACTUALIZADO (se regenera al usarse, no en cada guardado). Los propios
        // campos del resumen no cuentan (EventSummaryService guarda con saveQuietly).
        static::updating(function (Event $event) {
            $meta = ['ai_summary', 'ai_summary_at', 'ai_summary_stale'];
            $dirty = array_diff(array_keys($event->getDirty()), $meta);
            if ($dirty !== []) {
                $event->ai_summary_stale = true;
            }
        });
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
