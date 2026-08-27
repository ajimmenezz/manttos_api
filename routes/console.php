<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retención de la Auditoría: conservar solo los últimos 90 días.
Schedule::command('activity:prune')->dailyAt('03:15');

// Misma retención para la bitácora de errores de la app móvil.
Schedule::command('app-errors:prune')->dailyAt('03:20');
