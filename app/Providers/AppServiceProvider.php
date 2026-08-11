<?php

namespace App\Providers;

use App\Models\AppSetting;
use App\Observers\ActivityObserver;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un solo ActivityLogger por request: el observer y el middleware comparten su
        // contador para no duplicar la auditoría.
        $this->app->scoped(ActivityLogger::class);
    }

    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return $user->hasRole('superadmin') ? true : null;
        });

        // Auditoría: observar todos los modelos de la lista blanca.
        foreach (array_keys(ActivityLogger::MODULES) as $model) {
            $model::observe(ActivityObserver::class);
        }

        // Inyectar colores, logo y nombre en todos los templates de correo
        View::composer('emails.*', function ($view) {
            $settings = AppSetting::allAsMap();
            $view->with([
                'ec'      => AppSetting::emailColors(),
                'logoUrl' => $settings['logo_url']  ?? null,
                'appName' => $settings['app_name']  ?? 'Sistema de Mantenimientos',
            ]);
        });
    }
}
