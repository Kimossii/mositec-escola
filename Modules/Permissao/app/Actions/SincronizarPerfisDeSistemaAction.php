<?php

namespace Modules\Permissao\Actions;

use RuntimeException;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;

/**
 * Perfis de sistema e permissões por omissão de cada um, no tenant corrente.
 * Usada pelo provisionador de tenants e pelos seeders (RoleSeeder, RolePermissaoSeeder).
 * Idempotente. O catálogo global (modulos, acoes) tem de existir: ModuloSeeder e AcaoSeeder.
 */
class SincronizarPerfisDeSistemaAction
{
    /**
     * Concede a ADMIN_ESCOLA exactamente o que as antigas gates fixas
     * ('gerir-ano-letivo', 'gerir-estabelecimento', 'gerir-usuarios',
     * 'gerir-permissoes') já davam, para migrar sem regressão de acesso.
     * FUNCIONARIO ganha gestão de contas (usuario.*) por decisão de desenho
     * aprovada — nunca autorizacao.* (perfis/permissões/administradores
     * fica reservado a ADMIN_ESCOLA).
     */
    private const PERMISSOES_POR_PERFIL = [
        Perfil::ADMIN_ESCOLA->value => [
            Modulo::ANO_LECTIVO->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::ESTABELECIMENTO->value => ['ver', 'editar'],
            Modulo::HORARIO->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::USUARIO->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::AUTORIZACAO->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::INFRAESTRUTURA->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::TURMAS->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::CURSO->value => ['ver', 'criar', 'editar'],
            Modulo::PLANO_CURRICULAR->value => ['ver', 'criar', 'editar'],
            Modulo::DOCUMENTO_PESSOA->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::SENHA_UTILIZADOR->value => ['editar'],
            Modulo::DISCIPLINA->value => ['ver', 'criar', 'editar'],
            Modulo::ALUNO->value => ['ver', 'criar', 'editar'],
            Modulo::MATRICULA->value => ['ver', 'criar', 'editar', 'eliminar'],
        ],
        Perfil::FUNCIONARIO->value => [
            Modulo::USUARIO->value => ['ver', 'criar', 'editar'],
        ],
    ];

    public function executar(): void
    {
        $this->criarPerfis();
        $this->concederPermissoes();
    }

    public function criarPerfis(): void
    {
        foreach (Perfil::cases() as $perfil) {
            Role::updateOrCreate(
                ['nome' => $perfil->value],
                ['descricao' => $perfil->label()],
            );
        }
    }

    public function concederPermissoes(): void
    {
        foreach (self::PERMISSOES_POR_PERFIL as $roleNome => $mapa) {
            $role = Role::where('nome', $roleNome)->first()
                ?? throw new RuntimeException('Perfil de sistema ' . Perfil::from($roleNome)->name . ' inexistente: crie os perfis antes de conceder permissões.');

            foreach ($mapa as $moduloNome => $acoes) {
                $modulo = ModuloRegistro::where('nome', $moduloNome)->first()
                    ?? throw new RuntimeException('Módulo ' . Modulo::from($moduloNome)->name . ' em falta no catálogo global: corra `php artisan db:seed --force` (ModuloSeeder/AcaoSeeder) antes de criar tenants.');

                foreach ($acoes as $acaoNome) {
                    $acao = Acao::where('nome', $acaoNome)->first()
                        ?? throw new RuntimeException("Acção '{$acaoNome}' em falta no catálogo global: corra `php artisan db:seed --force` (ModuloSeeder/AcaoSeeder) antes de criar tenants.");

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
