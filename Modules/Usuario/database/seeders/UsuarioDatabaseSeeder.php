<?php

namespace Modules\Usuario\Database\Seeders;


use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\User;

class UsuarioDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // $this->call([]);
        $now = now();
        User::updateOrCreate(
            ['email' => 'superadmin@example.com'],
            [
                'name' => 'SuperAdmin',
                'password' => Hash::make('Mositec123!'),
                'dados_pessoa_id' => null,
                'estado' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}
