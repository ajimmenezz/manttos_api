<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    /** Registros inmutables: solo created_at (sin updated_at). */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'impersonator_id', 'source', 'module', 'action', 'description',
        'subject_type', 'subject_id', 'subject_label', 'properties',
        'method', 'route', 'path', 'status', 'ip', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function impersonator()
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }
}
