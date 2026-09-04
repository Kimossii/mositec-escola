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
     * ('gerir-ano-letivo', 'gerir-estabelecimento', 'gerir-usuarios',
     * 'gerir-permissoes') já davam, para migrar sem regressão de acesso.
     * FUNCIONARIO ganha gestão de contas (usuario.*) por decisão de desenho
     * aprovada — nunca autorizacao.* (perfis/permissões/administradores
     * fica reservado a ADMIN_ESCOLA).
     */
    public function run(): void
    {
        $mapaPorRole = [
            Perfil::ADMIN_ESCOLA->value => [
                Modulo::ANO_LECTIVO->value => ['ver', 'criar', 'editar', 'eliminar'],
                Modulo::ESTABELECIMENTO->value => ['ver', 'editar'],
                Modulo::HORARIO->value => ['ver', 'criar', 'editar', 'eliminar'],
                Modulo::USUARIO->value => ['ver', 'criar', 'editar', 'eliminar'],
                Modulo::AUTORIZACAO->value => ['ver', 'criar', 'editar', 'eliminar'],
            ],
            Perfil::FUNCIONARIO->value => [
                Modulo::USUARIO->value => ['ver', 'criar', 'editar'],
            ],
        ];

        foreach ($mapaPorRole as $roleNome => $mapa) {
            $role = Role::where('nome', $roleNome)->first();
            if (!$role) {
                Log::warning("RolePermissaoSeeder: role com nome={$roleNome} não encontrada — permissões não concedidas.");
                continue;
            }

            foreach ($mapa as $moduloNome => $acoes) {
                $modulo = ModuloRegistro::where('nome', $moduloNome)->first();
                if (!$modulo) {
                    Log::warning("RolePermissaoSeeder: módulo com nome={$moduloNome} não encontrado — permissões não concedidas a role {$roleNome}.");
                    continue;
                }

                foreach ($acoes as $acaoNome) {
                    $acao = Acao::where('nome', $acaoNome)->first();
                    if (!$acao) {
                        Log::warning("RolePermissaoSeeder: acção '{$acaoNome}' não encontrada — permissão não concedida (role={$roleNome}, módulo={$moduloNome}).");
                        continue;
                    }

                    RolePermissao::firstOrCreate([
                        'role_id' => $role->id,
                        'modulo_id' => $modulo->id,
                        'acao_id' => $acao->id,
                    ]);
                }
            }
        }
    }
}
