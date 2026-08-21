<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Solicitud de respaldo completo (ver App\Jobs\GenerateSnapshot). */
class SnapshotExport extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = ['status', 'file_name', 'size', 'no_media', 'error', 'requested_by'];

    protected $casts = ['no_media' => 'boolean', 'size' => 'integer'];

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }
}
