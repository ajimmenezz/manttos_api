<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FloorPlan extends Model
{
    protected $fillable = [
        'site_id',
        'name',
        'image_url',
        'image_width',
        'image_height',
        'sort_order',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'image_width'  => 'integer',
            'image_height' => 'integer',
            'sort_order'   => 'integer',
            'is_active'    => 'boolean',
        ];
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function placements()
    {
        return $this->hasMany(DevicePlacement::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
