<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $keys = [
            'smtp_host'       => null,
            'smtp_port'       => '587',
            'smtp_encryption' => 'tls',
            'smtp_username'   => null,
            'smtp_password'   => null,
            'smtp_from_email' => null,
            'smtp_from_name'  => null,
        ];

        foreach ($keys as $key => $value) {
            DB::table('app_settings')->insertOrIgnore([
                'key'        => $key,
                'value'      => $value,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', [
            'smtp_host', 'smtp_port', 'smtp_encryption',
            'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name',
        ])->delete();
    }
};
