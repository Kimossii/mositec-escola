<?php

namespace Modules\Autenticacao\Database\Seeders;


use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name'     => 'Administrador MosiTec',
            'email'    => 'admin@mositec.gmail.com',
            'password' => Hash::make('12345678'),
            'estado' => 1,
        ]);
    }
}
