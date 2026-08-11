<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImpersonationLog extends Model
{
    protected $fillable = [
        'impersonator_id',
        'impersonated_id',
        'token_id',
        'ip',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }

    public function impersonator()
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function impersonated()
    {
        return $this->belongsTo(User::class, 'impersonated_id');
    }
}
