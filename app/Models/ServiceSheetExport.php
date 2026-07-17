<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud de exportación (ZIP) de hojas de servicio de un cliente en un rango de
 * fechas. La genera un Job en segundo plano; al terminar deja `file_path` y notifica.
 */
class ServiceSheetExport extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'client_id', 'from_date', 'to_date', 'status',
        'requested_by', 'event_count', 'file_path', 'error',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date'   => 'date',
        ];
    }

    public function client()
    {
        return $this->belongsTo(\App\Models\Client::class);
    }

    public function requester()
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }
}
