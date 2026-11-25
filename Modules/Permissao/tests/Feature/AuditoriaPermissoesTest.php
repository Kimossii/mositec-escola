<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Core\Models\AuditoriaTenant;
use Modules\Permissao\Actions\AlternarEstadoPerfilAction;
use Modules\Permissao\Actions\AtribuirPerfilAction;
use Modules\Permissao\Actions\AtualizarPerfilAction;
use Modules\Permissao\Actions\CriarPerfilAction;
use Modules\Permissao\Actions\EliminarPerfilAction;
use Modules\Permissao\Actions\RemoverPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Tests\TestCase;

class AuditoriaPermissoesTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function linhas(string $accao)
    {
        return AuditoriaTenant::query()->where('accao', $accao)->get();
    }

    private function celula(ModuloEnum $modulo, string $acao, bool $permitido = true): array
    {
        return [
            'modulo_id' => Modulo::where('nome', $modulo->value)->value('id'),
            'acao_id' => Acao::where('nome', $acao)->value('id'),
            'permitido' => $permitido,
        ];
    }

    public function test_atribuir_perfil_regista_antes_e_depois(): void
    {
        $user = $this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'f@example.com');
        $extra = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Secretaria', 'estado' => 1]);
        $this->actingAs($user);

        app(AtribuirPerfilAction::class)->executar($user, $extra->id);

        $linha = $this->linhas('perfil.atribuido')->sole();
        $this->assertSame('users', $linha->entidade_tipo);
        $this->assertSame($user->id, $linha->entidade_id);
        $this->assertNotContains('Secretaria', $linha->detalhe['antes']['perfis']);
        $this->assertContains('Secretaria', $linha->detalhe['depois']['perfis']);
    }

    public function test_remover_perfil_regista_com_depois_sem_o_perfil(): void
    {
        $user = $this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'f@example.com');
        $extra = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Secretaria', 'estado' => 1]);
        $user->roles()->attach($extra->id);
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com'));

        app(RemoverPerfilAction::class)->executar($user, $extra);

        $linha = $this->linhas('perfil.removido')->sole();
        $this->assertContains('Secretaria', $linha->detalhe['antes']['perfis']);
        $this->assertNotContains('Secretaria', $linha->detalhe['depois']['perfis']);
    }

    public function test_sincronizar_permissoes_do_utilizador_regista_so_as_diferencas_e_remocoes(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $alvo = $this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'f@example.com');
        $this->actingAs($admin);
        $acao = app(SincronizarPermissoesUtilizadorAction::class);

        $acao->executar($alvo, [$this->celula(ModuloEnum::CURSO, 'ver'), $this->celula(ModuloEnum::CURSO, 'criar', false)]);
        $acao->executar($alvo, [$this->celula(ModuloEnum::CURSO, 'ver')]); // remove `criar`
        $acao->executar($alvo, [$this->celula(ModuloEnum::CURSO, 'ver')]); // sem alterações

        $linhas = $this->linhas('permissoes_utilizador.sincronizadas');
        $this->assertCount(2, $linhas);
        $this->assertSame(['curso.criar' => null, 'curso.ver' => null], $linhas[0]->detalhe['antes']);
        $this->assertSame(['curso.criar' => false, 'curso.ver' => true], $linhas[0]->detalhe['depois']);
        $this->assertSame(['curso.criar' => false], $linhas[1]->detalhe['antes']);
        $this->assertSame(['curso.criar' => null], $linhas[1]->detalhe['depois']);
    }

    public function test_sincronizar_permissoes_do_perfil_regista_e_nao_repete(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Secretaria', 'estado' => 1]);
        $this->actingAs($admin);
        $acao = app(SincronizarPermissoesPerfilAction::class);

        $acao->executar($role, [$this->celula(ModuloEnum::CURSO, 'ver')]);
        $acao->executar($role, [$this->celula(ModuloEnum::CURSO, 'ver')]);

        $linha = $this->linhas('permissoes_perfil.sincronizadas')->sole();
        $this->assertSame('roles', $linha->entidade_tipo);
        $this->assertSame(['curso.ver' => null], $linha->detalhe['antes']);
        $this->assertSame(['curso.ver' => true], $linha->detalhe['depois']);
    }

    public function test_ciclo_de_vida_do_perfil_fica_registado(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com'));

        $role = app(CriarPerfilAction::class)->executar(['descricao' => 'Secretaria', 'estado' => 1]);
        app(AtualizarPerfilAction::class)->executar($role, ['descricao' => 'Secretaria Geral']);
        app(AlternarEstadoPerfilAction::class)->executar($role->fresh());
        app(EliminarPerfilAction::class)->executar($role->fresh());

        $this->assertSame(['descricao' => 'Secretaria', 'estado' => 1], $this->linhas('perfil.criado')->sole()->detalhe['depois']);
        $this->assertSame(['descricao' => 'Secretaria'], $this->linhas('perfil.actualizado')->sole()->detalhe['antes']);
        $this->assertSame(['descricao' => 'Secretaria Geral'], $this->linhas('perfil.actualizado')->sole()->detalhe['depois']);
        $estado = $this->linhas('perfil.estado_alterado')->sole();
        $this->assertSame(['estado' => 1], $estado->detalhe['antes']);
        $this->assertSame(['estado' => 0], $estado->detalhe['depois']);
        $eliminado = $this->linhas('perfil.eliminado')->sole();
        $this->assertSame(['descricao' => null, 'estado' => null], $eliminado->detalhe['depois']);
    }

    public function test_falha_da_action_nao_deixa_rasto(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com'));
        $sistema = Role::where('nome', Perfil::FUNCIONARIO->value)->firstOrFail();

        try {
            app(EliminarPerfilAction::class)->executar($sistema);
            $this->fail('Devia recusar eliminar um perfil de sistema.');
        } catch (ValidationException) {
        }

        $this->assertSame(0, $this->linhas('perfil.eliminado')->count());
    }
}
