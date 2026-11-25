<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** O encarregado herda do perfil Aluno o que pode ver dos educandos, sem copiar permissões para a sua conta. */
class PermissoesHerdadasDoAlunoTest extends TestCase
{
    use RefreshDatabase;

    private User $encarregado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);

        $this->encarregado = User::create(['name' => 'Encarregado', 'email' => 'enc@example.com', 'password' => Hash::make('x')]);
        $this->encarregado->roles()->attach(Role::where('nome', Perfil::ENCARREGADO->value)->value('id'));
    }

    public function test_conjunto_do_perfil_aluno_tem_as_permissoes_do_aluno(): void
    {
        $this->assertEqualsCanonicalizing(
            ['nota.ver-proprias', 'frequencia.ver-proprias'],
            app(PermissionResolver::class)->conjuntoDoPerfilAluno(),
        );
    }

    public function test_encarregado_nao_recebe_as_permissoes_do_aluno_no_seu_conjunto(): void
    {
        $this->assertSame(['area-encarregado.ver'], app(PermissionResolver::class)->conjuntoConcedido($this->encarregado));
    }

    public function test_encarregado_nao_abre_as_paginas_do_aluno(): void
    {
        $this->actingAs($this->encarregado);

        $this->get('/minhas-notas')->assertForbidden();
        $this->get('/minhas-faltas')->assertForbidden();
    }

    public function test_acompanha_mudancas_do_perfil_aluno(): void
    {
        RolePermissao::create([
            'role_id' => Role::where('nome', Perfil::ALUNO->value)->value('id'),
            'modulo_id' => Modulo::where('nome', ModuloEnum::NOTA->value)->value('id'),
            'acao_id' => Acao::where('nome', 'ver')->value('id'),
        ]);

        $this->assertContains('nota.ver', app(PermissionResolver::class)->conjuntoDoPerfilAluno());
    }
}
