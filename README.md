# Sistema de Mantenimientos — API

Backend REST del **Sistema de Mantenimientos**: gestión de mantenimientos preventivos
de dispositivos (detección de incendio, CCTV, etc.) para cadenas hoteleras.
Jerarquía principal: **Clientes → Sitios → Directorios → Dispositivos → Mantenimientos**.

Es sólo una API (JSON). El acceso de usuarios es a través de la **aplicación web**
(Next.js) y la **app móvil** (Expo); este servicio no expone páginas navegables.

## Stack

- **PHP 8.2** · **Laravel 12**
- **PostgreSQL**
- **Sanctum** (autenticación por tokens) · **spatie/laravel-permission** (roles y permisos)
- Guard `web` (compatible con tokens Sanctum)

## Requisitos

- PHP 8.2+ con las extensiones habituales de Laravel (pdo_pgsql, mbstring, openssl, etc.)
- Composer
- PostgreSQL

## Puesta en marcha (desarrollo)

```bash
composer install
cp .env.example .env        # y configurar DB_*, APP_URL, SUPERADMIN_*, etc.
php artisan key:generate
php artisan migrate --seed  # crea permisos, roles y el superadmin desde .env
php artisan storage:link    # para servir archivos subidos (planos, evidencias, logos)

# Levantar en la red local (para que el celular en la misma red pueda conectarse):
php artisan serve --host=0.0.0.0 --port=8000
```

> El seeder de producción (`db:seed`) ejecuta lo indispensable: permisos, roles,
> el superadministrador (desde variables `SUPERADMIN_*` del `.env`) y los catálogos base.

## Rutas

- **`/api/*`** — toda la superficie de la API (ver `routes/api.php`).
- **`/api/v1/*`** — fachada pública versionada para llaves de API de clientes.
- **`/up`** — health check.
- **`/`** — página de estado branded (sin contenido navegable).

Las rutas o recursos inexistentes responden con un mensaje propio del proyecto
(JSON para la API; página branded para el navegador) — ver `bootstrap/app.php` y
`resources/views/errors/`.

## Convenciones

- **Sin hard deletes**: se usa `is_active` / soft deletes (salvo excepciones documentadas).
- **Autorización** con `abort_unless` / `abort_if` y permisos de Spatie; `superadmin`
  hace bypass vía `Gate::before`.
- **Multi-tenant white-label** por dominio para la apariencia del front
  (`app_settings` scopeado por `tenant`); los datos no se aíslan por tenant.
- **Dispositivos**: patrón write-through (`devices.custom_fields` JSONB para UI +
  `device_field_values` normalizada para reportes/filtros).

## Deploy

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
# Reiniciar PHP-FPM tras cambios de clases/config (config:clear no limpia OPcache):
#   systemctl restart php8.2-fpm   (ajustar a la versión/instancia del servidor)
```

---

Software propietario del Sistema de Mantenimientos. Uso interno.
