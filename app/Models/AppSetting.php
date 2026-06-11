<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AppSetting extends Model
{
    protected $primaryKey = 'key';
    public    $incrementing = false;
    protected $keyType = 'string';
    public    $timestamps = false;

    protected $fillable = ['key', 'value'];

    public const ALLOWED_KEYS = ['app_name', 'logo_url', 'login_bg_url', 'color_preset'];

    public static function allAsMap(): array
    {
        return DB::table('app_settings')
            ->pluck('value', 'key')
            ->toArray();
    }

    public static function setValue(string $key, ?string $value): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()]
        );
    }

    /**
     * Devuelve los colores hex del preset activo para usarlos en templates de correo.
     * Claves: primary, primary_dark, header_from, header_to, btn_shadow, accent_from, accent_to
     */
    public static function emailColors(): array
    {
        $preset = self::allAsMap()['color_preset'] ?? 'blue';

        $map = [
            'blue'   => ['primary' => '#2563eb', 'primary_dark' => '#1d4ed8', 'header_from' => '#0f1f3d', 'header_to' => '#1e3a8a', 'btn_shadow' => 'rgba(37,99,235,.35)',   'accent_from' => '#2563eb', 'accent_to' => '#4f46e5'],
            'indigo' => ['primary' => '#4f46e5', 'primary_dark' => '#4338ca', 'header_from' => '#1e1b4b', 'header_to' => '#312e81', 'btn_shadow' => 'rgba(79,70,229,.35)',   'accent_from' => '#4f46e5', 'accent_to' => '#7c3aed'],
            'violet' => ['primary' => '#7c3aed', 'primary_dark' => '#6d28d9', 'header_from' => '#2e1065', 'header_to' => '#4c1d95', 'btn_shadow' => 'rgba(124,58,237,.35)',  'accent_from' => '#7c3aed', 'accent_to' => '#a855f7'],
            'green'  => ['primary' => '#16a34a', 'primary_dark' => '#15803d', 'header_from' => '#052e16', 'header_to' => '#14532d', 'btn_shadow' => 'rgba(22,163,74,.35)',   'accent_from' => '#16a34a', 'accent_to' => '#059669'],
            'orange' => ['primary' => '#ea580c', 'primary_dark' => '#c2410c', 'header_from' => '#431407', 'header_to' => '#7c2d12', 'btn_shadow' => 'rgba(234,88,12,.35)',  'accent_from' => '#ea580c', 'accent_to' => '#f59e0b'],
            'rose'   => ['primary' => '#e11d48', 'primary_dark' => '#be123c', 'header_from' => '#4c0519', 'header_to' => '#881337', 'btn_shadow' => 'rgba(225,29,72,.35)',  'accent_from' => '#e11d48', 'accent_to' => '#f43f5e'],
        ];

        return $map[$preset] ?? $map['blue'];
    }
}
