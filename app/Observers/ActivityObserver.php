<?php

namespace App\Observers;

use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Observa los modelos auditados y delega en ActivityLogger. Se registra sobre la
 * lista blanca `ActivityLogger::MODULES` desde AppServiceProvider.
 */
class ActivityObserver
{
    public function created(Model $model): void  { $this->log($model, 'created'); }
    public function updated(Model $model): void  { $this->log($model, 'updated'); }
    public function deleted(Model $model): void  { $this->log($model, 'deleted'); }
    public function restored(Model $model): void { $this->log($model, 'restored'); }

    private function log(Model $model, string $action): void
    {
        app(ActivityLogger::class)->logModel($model, $action);
    }
}
