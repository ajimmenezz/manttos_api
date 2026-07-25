<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Traza de una ejecución de automatización de evento (dedupe de run_once + bitácora).
 */
class EventAutomationRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_automation_id',
        'event_id',
        'status',
        'result',
        'error',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'ran_at' => 'datetime',
        ];
    }

    public function automation()
    {
        return $this->belongsTo(EventTypeAutomation::class, 'event_automation_id');
    }
}
