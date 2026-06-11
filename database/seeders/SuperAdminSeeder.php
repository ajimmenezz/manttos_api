<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email    = env('SUPERADMIN_EMAIL',    'admin@example.com');
        $password = env('SUPERADMIN_PASSWORD', 'Cambiar@2025!');
        $name     = env('SUPERADMIN_NAME',     'Super Administrador');

        $superAdmin = User::firstOrCreate(
            ['email' => $email],
            [
                'name'                 => $name,
                'password'             => $password,
                'must_change_password' => true,
                'is_active'            => true,
            ]
        );

        $superAdmin->syncRoles(['superadmin']);
    }
}
