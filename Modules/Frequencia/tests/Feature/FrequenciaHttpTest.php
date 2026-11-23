<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\FrequenciaDefinicoes;
use Modules\Frequencia\Models\Justificacao;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class FrequenciaHttpTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private array $c;

    private User $professor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00'); // segunda-feira
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->c = $this->cenarioDeFrequencia();
        $this->professor = $this->utilizadorDoDocente($this->c['docente']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function abrirAula(): Aula
    {
        return app(AbrirChamadaAction::class)->executar($this->professor, $this->c['turma']->id, '2026-03-02', $this->c['tempo1']->id);
    }

    private function comPerfil(Perfil $perfil, string $email): User
    {
        $u = User::create(['name' => $perfil->value, 'email' => $email, 'password' => Hash::make('x')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $u;
    }

    public function test_dia_lista_as_aulas_do_professor(): void
    {
        $this->actingAs($this->professor)->get(route('frequencia.dia', ['data' => '2026-03-02']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Frequencia/Dia')
                ->where('data', '2026-03-02')
                ->where('hoje', '2026-03-02')
                ->has('aulas', 1)
                ->where('aulas.0.estado', 'pendente'));
    }

    public function test_dia_sem_data_usa_hoje(): void
    {
        $this->actingAs($this->professor)->get(route('frequencia.dia'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data', '2026-03-02')->has('aulas', 1));
    }

    public function test_chamada_abre_e_devolve_registos(): void
    {
        $this->actingAs($this->professor)
            ->get(route('frequencia.chamada', ['turma_id' => $this->c['turma']->id, 'data' => '2026-03-02', 'horario_id' => $this->c['tempo1']->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Frequencia/Chamada')
                ->has('chamada.registos', 3)
                ->where('chamada.editavel', true)
                ->where('janelaDias', 7));

        $this->assertSame(1, Aula::count());
    }

    public function test_chamada_de_data_futura_devolve_erro_de_validacao(): void
    {
        $this->actingAs($this->professor)
            ->get(route('frequencia.chamada', ['turma_id' => $this->c['turma']->id, 'data' => '2026-03-09', 'horario_id' => $this->c['tempo1']->id]))
            ->assertSessionHasErrors(['data']);

        $this->assertSame(0, Aula::count());
    }

    public function test_presencas_valido_redirecciona_com_success(): void
    {
        $aula = $this->abrirAula();
        $alunoId = $this->c['alunos'][0]->id;

        $this->actingAs($this->professor)->put(route('frequencia.presencas', $aula), [
            'presencas' => [['matricula_id' => $alunoId, 'estado' => EstadoPresenca::FALTA->value, 'observacao' => 'Sem aviso']],
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $registo = $aula->registos()->where('matricula_id', $alunoId)->first();
        $this->assertSame(EstadoPresenca::FALTA, $registo->estado);
        $this->assertSame('Sem aviso', $registo->observacao);
    }

    public function test_presencas_invalidas_devolvem_erro(): void
    {
        $aula = $this->abrirAula();

        $this->actingAs($this->professor)->put(route('frequencia.presencas', $aula), ['presencas' => [['matricula_id' => 1, 'estado' => 9]]])
            ->assertSessionHasErrors(['presencas.0.estado']);
        $this->actingAs($this->professor)->put(route('frequencia.presencas', $aula), [])
            ->assertSessionHasErrors(['presencas']);
    }

    public function test_presencas_fora_da_janela_erro_para_professor(): void
    {
        $aula = $this->abrirAula();
        Carbon::setTestNow('2026-03-20 10:00:00');

        $this->actingAs($this->professor)->put(route('frequencia.presencas', $aula), [
            'presencas' => [['matricula_id' => $this->c['alunos'][0]->id, 'estado' => EstadoPresenca::FALTA->value]],
        ])->assertSessionHasErrors(['aula']);
    }

    public function test_nao_leccionada(): void
    {
        $aula = $this->abrirAula();

        $this->actingAs($this->professor)->patch(route('frequencia.nao-leccionada', $aula), ['motivo' => 'Greve'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->professor)->patch(route('frequencia.nao-leccionada', $aula), [])
            ->assertSessionHasErrors(['motivo']);

        $this->assertSame(EstadoAula::NAO_LECCIONADA, $aula->fresh()->estado);
    }

    public function test_justificar_e_remover(): void
    {
        $aula = $this->abrirAula();
        $registo = $aula->registos->first();
        $registo->update(['estado' => EstadoPresenca::FALTA]);

        $this->actingAs($this->professor)->post(route('frequencia.justificar', $registo), ['motivo' => 'Doença', 'documento_referencia' => 'Atestado 1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Justificacao::count());

        $this->actingAs($this->professor)->delete(route('frequencia.remover-justificacao', $registo), ['motivo' => 'Engano'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, Justificacao::count());
    }

    public function test_pendentes_so_com_listar(): void
    {
        $this->actingAs($this->comPerfil(Perfil::FUNCIONARIO, 'sec@example.com'))->get(route('frequencia.pendentes'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Frequencia/Pendentes')
                ->has('pendentes', 1)
                ->has('opcoes.turmas', 1)
                ->where('janelaDias', 7)
                ->where('podeConfigurar', false));
    }

    public function test_definicoes_so_com_configurar(): void
    {
        $admin = $this->comPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $this->actingAs($admin)->put(route('frequencia.definicoes'), ['janela_correccao_dias' => 14])->assertSessionHasNoErrors();
        $this->assertSame(14, FrequenciaDefinicoes::first()->janela_correccao_dias);

        $this->actingAs($admin)->put(route('frequencia.definicoes'), ['janela_correccao_dias' => 90])->assertSessionHasErrors(['janela_correccao_dias']);
    }

    public function test_minhas_faltas_so_do_aluno_autenticado(): void
    {
        $this->aulaComRegistos($this->c, '2026-03-02', [EstadoPresenca::FALTA, EstadoPresenca::FALTA]);
        $aluno = $this->c['alunos'][0]->aluno;
        $utilizador = $this->comPerfil(Perfil::ALUNO, 'aluna@example.com');
        $utilizador->update(['dados_pessoa_id' => $aluno->dados_pessoa_id]);

        $this->actingAs($utilizador)->get(route('frequencia.minhas-faltas'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Frequencia/MinhasFaltas')
                ->has('blocos', 1)
                ->where('blocos.0.resumo.total', 1));
    }
}
