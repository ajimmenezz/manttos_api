<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskDuration extends Model
{
    protected $fillable = [
        'system_id',
        'device_type_id',
        'activity_type_id',
        'minutes',
    ];

    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
        ];
    }
}
