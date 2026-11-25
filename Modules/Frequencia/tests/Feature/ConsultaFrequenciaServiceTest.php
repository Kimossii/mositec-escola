<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Actions\MarcarNaoLeccionadaAction;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Models\Justificacao;
use Modules\Frequencia\Services\ConsultaFrequenciaService;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ConsultaFrequenciaServiceTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00'); // segunda-feira
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servico(): ConsultaFrequenciaService
    {
        return app(ConsultaFrequenciaService::class);
    }

    public function test_aulas_do_dia_marcam_estado(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->criarEntrada($c, ['horario_id' => $c['tempo2']->id, 'disciplina_id' => $c['outraDisciplina']->id, 'vigencia_inicio' => '2026-01-01']);
        $prof = $this->utilizadorDoDocente($c['docente']);

        $antes = $this->servico()->aulasDoDia($prof, Carbon::parse('2026-03-02'));
        $this->assertSame(['pendente', 'pendente'], array_column($antes, 'estado'));
        $this->assertSame(['Matemática', 'Português'], array_column($antes, 'disciplina_nome'));
        $this->assertSame('07:00', $antes[0]['horario_inicio']);

        $aula1 = app(AbrirChamadaAction::class)->executar($prof, $c['turma']->id, '2026-03-02', $c['tempo1']->id);
        $aula2 = app(AbrirChamadaAction::class)->executar($prof, $c['turma']->id, '2026-03-02', $c['tempo2']->id);
        app(MarcarNaoLeccionadaAction::class)->executar($prof, $aula2, 'Greve');

        $depois = $this->servico()->aulasDoDia($prof, Carbon::parse('2026-03-02'));
        $this->assertSame(['feita', 'nao_leccionada'], array_column($depois, 'estado'));
        $this->assertSame([$aula1->id, $aula2->id], array_column($depois, 'aula_id'));
        $this->assertSame([], $this->servico()->aulasDoDia($this->criarProfessor('outro@example.com'), Carbon::parse('2026-03-02')));
    }

    public function test_chamada_lista_registos_e_editavel_conforme_a_janela(): void
    {
        $c = $this->cenarioDeFrequencia();
        $prof = $this->utilizadorDoDocente($c['docente']);
        $aula = app(AbrirChamadaAction::class)->executar($prof, $c['turma']->id, '2026-03-02', $c['tempo1']->id);
        $falta = $aula->registos->firstWhere('matricula_id', $c['alunos'][1]->id);
        $falta->update(['estado' => EstadoPresenca::FALTA]);
        Justificacao::create(['registo_id' => $falta->id, 'motivo' => 'Doença', 'justificada_em' => Carbon::now()]);

        $r = $this->servico()->chamada($aula->fresh(), $prof);

        $this->assertTrue($r['editavel']);
        $this->assertFalse($r['fora_da_janela']);
        $this->assertSame(['Ana Aluna', 'Bruno Aluno', 'Carla Aluna'], array_column($r['registos'], 'nome'));
        $bruno = $r['registos'][1];
        $this->assertSame(EstadoPresenca::FALTA->value, $bruno['estado']);
        $this->assertTrue($bruno['justificada']);
        $this->assertSame('Doença', $bruno['motivo_justificacao']);
        $this->assertFalse($r['registos'][0]['justificada']);
        $this->assertSame('Matemática', $r['aula']['disciplina_nome']);

        Carbon::setTestNow('2026-03-20 10:00:00');
        $this->assertFalse($this->servico()->chamada($aula->fresh(), $prof)['fora_da_janela'] === false);
        $this->assertFalse($this->servico()->chamada($aula->fresh(), $prof)['editavel']);
        $this->assertTrue($this->servico()->chamada($aula->fresh(), $this->funcionario())['editavel']);
    }

    public function test_pendentes_listam_aulas_sem_chamada_com_o_docente(): void
    {
        $c = $this->cenarioDeFrequencia();

        $linhas = $this->servico()->pendentes(null);

        $this->assertCount(1, $linhas);
        $this->assertSame('2026-03-02', $linhas[0]['data']);
        $this->assertSame('Matemática', $linhas[0]['disciplina_nome']);
        $this->assertSame('Docente Titular', $linhas[0]['docente_nome']);
        $this->assertSame([], $this->servico()->pendentes($c['turma']->id + 999));
    }

    public function test_minhas_faltas_so_do_proprio_aluno(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->aulaComRegistos($c, '2026-03-02', [EstadoPresenca::FALTA, EstadoPresenca::FALTA]);
        $aluna = $c['alunos'][0]->aluno;
        $utilizador = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => Hash::make('x'), 'dados_pessoa_id' => $aluna->dados_pessoa_id]);

        $blocos = $this->servico()->minhasFaltas($utilizador);

        $this->assertCount(1, $blocos);
        $this->assertSame($c['alunos'][0]->id, $blocos[0]['matricula_id']);
        $this->assertSame(1, $blocos[0]['resumo']['total']);
        $this->assertSame(['Matemática'], array_column($blocos[0]['por_disciplina'], 'nome'));
        $this->assertSame(1, $blocos[0]['por_disciplina'][0]['total']);
        $this->assertSame([], $this->servico()->minhasFaltas($this->criarProfessor('p@example.com')));
    }
}
