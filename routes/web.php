<?php

use Illuminate\Support\Facades\Route;

// Raíz del backend: página branded del Sistema de Mantenimientos (sin marca Laravel).
// Es sólo una API; el acceso real es por la aplicación web/móvil.
Route::get('/', fn () => response()->view('api'));
