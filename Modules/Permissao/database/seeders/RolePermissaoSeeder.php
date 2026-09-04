<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;

class RolePermissaoSeeder extends Seeder
{
    /**
     * Concede a ADMIN_ESCOLA exactamente o que as antigas gates fixas
     * ('gerir-ano-letivo', 'gerir-estabelecimento') já davam, para migrar
     * sem regressão de acesso. Nenhuma outra role recebe nada aqui.
     */
    public function run(): void
    {
        $adminEscola = Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first();
        if (!$adminEscola) {
            Log::warning('RolePermissaoSeeder: role ADMIN_ESCOLA não encontrada — seeder não vai conceder nenhuma permissão.');
            return;
        }

        $mapa = [
            Modulo::ANO_LECTIVO->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::ESTABELECIMENTO->value => ['ver', 'editar'],
            Modulo::HORARIO->value => ['ver', 'criar', 'editar', 'eliminar'],
        ];

        foreach ($mapa as $moduloNome => $acoes) {
            $modulo = ModuloRegistro::where('nome', $moduloNome)->first();
            if (!$modulo) {
                Log::warning("RolePermissaoSeeder: módulo com nome={$moduloNome} não encontrado — permissões de {$moduloNome} não foram concedidas a ADMIN_ESCOLA.");
                continue;
            }

            foreach ($acoes as $acaoNome) {
                $acao = Acao::where('nome', $acaoNome)->first();
                if (!$acao) {
                    Log::warning("RolePermissaoSeeder: acção '{$acaoNome}' não encontrada — permissão {$acaoNome}/módulo nome={$moduloNome} não foi concedida a ADMIN_ESCOLA.");
                    continue;
                }

                RolePermissao::firstOrCreate([
                    'role_id' => $adminEscola->id,
                    'modulo_id' => $modulo->id,
                    'acao_id' => $acao->id,
                ]);
            }
        }
    }
}
