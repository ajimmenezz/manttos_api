<?php

namespace App\Providers;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::before(function ($user, $ability) {
            return $user->hasRole('superadmin') ? true : null;
        });

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
