<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class FrequenciaAutorizacaoTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private array $c;

    private Aula $aula;

    private Registo $registo;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->c = $this->cenarioDeFrequencia();
        $this->aula = app(AbrirChamadaAction::class)->executar($this->utilizadorDoDocente($this->c['docente']), $this->c['turma']->id, '2026-03-02', $this->c['tempo1']->id);
        $this->registo = $this->aula->registos->first();
        $this->registo->update(['estado' => EstadoPresenca::FALTA]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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
        $c = $this->c;

        return [
            'dia' => ['get', route('frequencia.dia'), []],
            'chamada' => ['get', route('frequencia.chamada', ['turma_id' => $c['turma']->id, 'data' => '2026-03-02', 'horario_id' => $c['tempo1']->id]), []],
            'presencas' => ['put', route('frequencia.presencas', $this->aula), ['presencas' => [['matricula_id' => $c['alunos'][1]->id, 'estado' => 3]]]],
            'nao-leccionada' => ['patch', route('frequencia.nao-leccionada', $this->aula), ['motivo' => 'x']],
            'justificar' => ['post', route('frequencia.justificar', $this->registo), ['motivo' => 'x']],
            'remover-justificacao' => ['delete', route('frequencia.remover-justificacao', $this->registo), ['motivo' => 'x']],
            'pendentes' => ['get', route('frequencia.pendentes'), []],
            'definicoes' => ['put', route('frequencia.definicoes'), ['janela_correccao_dias' => 10]],
            'minhas-faltas' => ['get', route('frequencia.minhas-faltas'), []],
        ];
    }

    public function test_encarregado_recebe_403_em_tudo(): void
    {
        $this->como(Perfil::ENCARREGADO);

        foreach ($this->pedidos() as $nome => [$metodo, $url, $corpo]) {
            $this->{$metodo}($url, $corpo)->assertForbidden("encarregado em {$nome}");
        }
    }

    public function test_aluno_so_entra_em_minhas_faltas(): void
    {
        $this->como(Perfil::ALUNO);

        foreach ($this->pedidos() as $nome => [$metodo, $url, $corpo]) {
            $resposta = $this->{$metodo}($url, $corpo);
            $nome === 'minhas-faltas' ? $resposta->assertOk() : $resposta->assertForbidden("aluno em {$nome}");
        }
    }

    public function test_professor_nao_entra_em_pendentes_nem_definicoes_nem_minhas_faltas(): void
    {
        $this->como(Perfil::PROFESSOR);

        foreach (['pendentes', 'definicoes', 'minhas-faltas'] as $nome) {
            [$metodo, $url, $corpo] = $this->pedidos()[$nome];
            $this->{$metodo}($url, $corpo)->assertForbidden("professor em {$nome}");
        }

        $this->get(route('frequencia.dia'))->assertOk();
    }

    public function test_funcionario_ve_pendentes_abre_chamadas_corrige_e_justifica_mas_nao_configura(): void
    {
        $this->como(Perfil::FUNCIONARIO);

        $this->get(route('frequencia.pendentes'))->assertOk();
        $this->get(route('frequencia.dia', ['turma_id' => $this->c['turma']->id, 'data' => '2026-03-02']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('aulas', 1)->has('opcoes.turmas', 1));
        $this->get($this->pedidos()['chamada'][1])->assertOk();
        $this->post(route('frequencia.justificar', $this->registo), ['motivo' => 'Doença'])->assertSessionHasNoErrors();
        $this->put(route('frequencia.presencas', $this->aula), $this->pedidos()['presencas'][2])->assertSessionHasNoErrors();
        $this->patch(route('frequencia.nao-leccionada', $this->aula), ['motivo' => 'Greve'])->assertSessionHasNoErrors();
        $this->put(route('frequencia.definicoes'), ['janela_correccao_dias' => 10])->assertForbidden();
    }

    public function test_professor_nao_escolhe_turma_na_vista_do_dia(): void
    {
        $this->como(Perfil::PROFESSOR);

        $this->get(route('frequencia.dia', ['turma_id' => $this->c['turma']->id, 'data' => '2026-03-02']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('aulas', 0)->has('opcoes.turmas', 0));
    }

    public function test_professor_que_nao_lecciona_a_turma_recebe_403_ao_marcar(): void
    {
        $intruso = $this->criarDocente('Intruso', comAcesso: true)->utilizador();
        $this->actingAs($intruso);

        $this->put(route('frequencia.presencas', $this->aula), $this->pedidos()['presencas'][2])->assertForbidden();
        $this->post(route('frequencia.justificar', $this->registo), ['motivo' => 'x'])->assertForbidden();
    }

    public function test_aula_ou_registo_de_outro_tenant_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $deB = $this->noTenant($outro, fn () => $this->aulaComRegistos($this->cenarioDeFrequencia(), '2026-03-02'));
        $registoB = $this->noTenant($outro, fn () => $deB->registos()->first());
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->put(route('frequencia.presencas', $deB->id), $this->pedidos()['presencas'][2])->assertNotFound();
        $this->patch(route('frequencia.nao-leccionada', $deB->id), ['motivo' => 'x'])->assertNotFound();
        $this->post(route('frequencia.justificar', $registoB->id), ['motivo' => 'x'])->assertNotFound();
    }

    public function test_anonimo_e_redireccionado_para_login(): void
    {
        foreach ($this->pedidos() as [$metodo, $url, $corpo]) {
            $this->{$metodo}($url, $corpo)->assertRedirect();
        }
    }
}
