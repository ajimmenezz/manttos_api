<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventSlaSetting extends Model
{
    protected $fillable = ['client_id', 'enabled', 'matrix', 'priorities', 'calendar'];

    protected function casts(): array
    {
        return [
            'enabled'    => 'boolean',
            'matrix'     => 'array',
            'priorities' => 'array',
            'calendar'   => 'array',
        ];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
