<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtribuicaoDocenteAutorizacaoTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private AtribuicaoDocente $atribuicao;

    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);

        $this->c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente('Docente titular');
        $this->atribuicao = AtribuicaoDocente::create([
            'ano_lectivo_id' => $this->c['ano']->id, 'turma_id' => $this->c['turma']->id,
            'disciplina_id' => $this->c['disciplina']->id, 'docente_id' => $prof->id,
            'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-12-01',
        ]);
    }

    private function como(Perfil $perfil): User
    {
        $u = User::create(['name' => $perfil->value, 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);
        $this->actingAs($u);

        return $u;
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> */
    private function pedidos(): array
    {
        $a = $this->atribuicao;
        $corpo = ['docente_id' => $a->docente_id, 'turma_id' => $a->turma_id, 'disciplina_id' => $a->disciplina_id, 'papel' => 1, 'data_inicio' => '2026-06-15'];

        return [
            'index' => ['get', route('atribuicoes-docentes.index'), []],
            'store' => ['post', route('atribuicoes-docentes.store'), $corpo],
            'substituir' => ['post', route('atribuicoes-docentes.substituir'), ['turma_id' => $a->turma_id, 'disciplina_id' => $a->disciplina_id, 'novo_docente_id' => $a->docente_id, 'ultimo_dia_do_titular' => '2026-06-30', 'motivo' => 'x']],
            'update' => ['put', route('atribuicoes-docentes.update', $a), ['papel' => 0, 'data_inicio' => '2026-12-02']],
            'encerrar' => ['patch', route('atribuicoes-docentes.encerrar', $a), ['data_fim' => '2026-12-20', 'motivo' => 'x']],
            'destroy' => ['delete', route('atribuicoes-docentes.destroy', $a), []],
        ];
    }

    public function test_professor_aluno_e_encarregado_recebem_403_em_todas_as_rotas(): void
    {
        foreach ([Perfil::PROFESSOR, Perfil::ALUNO, Perfil::ENCARREGADO] as $perfil) {
            $this->como($perfil);

            foreach ($this->pedidos() as $nome => [$metodo, $url, $corpo]) {
                $this->{$metodo}($url, $corpo)->assertForbidden("{$perfil->value} em {$nome}");
            }
        }

        $this->assertNotNull($this->atribuicao->fresh());
    }

    public function test_funcionario_nao_elimina_mas_faz_o_resto(): void
    {
        $this->como(Perfil::FUNCIONARIO);

        $this->get(route('atribuicoes-docentes.index'))->assertOk();
        $this->put(route('atribuicoes-docentes.update', $this->atribuicao), ['papel' => 0, 'data_inicio' => '2026-12-02'])->assertSessionHasNoErrors();
        $this->patch(route('atribuicoes-docentes.encerrar', $this->atribuicao), ['data_fim' => '2026-12-20', 'motivo' => 'x'])->assertSessionHasNoErrors();
        $this->delete(route('atribuicoes-docentes.destroy', $this->atribuicao))->assertForbidden();
    }

    public function test_admin_escola_faz_tudo_incluindo_eliminar(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->get(route('atribuicoes-docentes.index'))->assertOk();
        $this->delete(route('atribuicoes-docentes.destroy', $this->atribuicao))->assertSessionHasNoErrors();
        $this->assertNull(AtribuicaoDocente::find($this->atribuicao->id));
    }

    public function test_anonimo_e_redireccionado_para_login(): void
    {
        foreach ($this->pedidos() as [$metodo, $url, $corpo]) {
            $this->{$metodo}($url, $corpo)->assertRedirect();
        }
    }
}
