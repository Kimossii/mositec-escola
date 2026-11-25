<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Horario\Services\ConsultaGrelhaService;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Modules\Turma\Models\Turno;
use Tests\TestCase;

class ConsultaGrelhaServiceTest extends TestCase
{
    use CenarioDeHorario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
    }

    private function servico(): ConsultaGrelhaService
    {
        return app(ConsultaGrelhaService::class);
    }

    public function test_por_turma_lista_todos_os_tempos_do_turno_e_marca_o_intervalo(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c, ['sala_id' => $c['sala']->id]);

        $r = $this->servico()->porTurma($c['turma']->id);

        $this->assertSame(['1º tempo', '2º tempo', 'Intervalo'], array_column($r['tempos'], 'nome'));
        $this->assertSame([true, true, false], array_column($r['tempos'], 'e_aula'));
        $this->assertSame('07:00', $r['tempos'][0]['hora_inicio']);
        $this->assertCount(1, $r['entradas']);
        $e = $r['entradas'][0];
        $this->assertSame(1, $e['dia_semana']);
        $this->assertSame('Matemática', $e['disciplina_nome']);
        $this->assertSame('Sala 1', $e['sala_nome']);
        $this->assertSame(['id' => $c['docente']->id, 'nome' => 'Docente Titular'], $e['docente']);
        $this->assertFalse($e['sem_docente']);
    }

    public function test_entrada_sem_titular_vem_marcada_sem_docente(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c);
        AtribuicaoDocente::query()->where('disciplina_id', $c['disciplina']->id)->delete();

        $e = $this->servico()->porTurma($c['turma']->id)['entradas'][0];

        $this->assertNull($e['docente']);
        $this->assertTrue($e['sem_docente']);
    }

    public function test_por_docente_inclui_aulas_como_substituto(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c);
        $substituto = $this->criarDocente('Substituto');
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $substituto->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-01-01',
        ]);

        $deSubstituto = $this->servico()->porDocente($substituto->id);
        $deTitular = $this->servico()->porDocente($c['docente']->id);
        $deOutro = $this->servico()->porDocente($this->criarDocente('Outro')->id);

        $this->assertCount(1, $deSubstituto['entradas']);
        $this->assertCount(1, $deTitular['entradas']);
        $this->assertSame([], $deOutro['entradas']);
        $this->assertSame(['1º tempo'], array_column($deSubstituto['tempos'], 'nome'));
    }

    public function test_do_utilizador_conta_sem_docente_devolve_vazio(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c);
        $comConta = $this->criarDocente('Com Conta', comAcesso: true);
        $this->atribuirTitular($c, $comConta, $c['outraDisciplina']->id);
        $this->criarEntrada($c, ['dia_semana' => 2, 'disciplina_id' => $c['outraDisciplina']->id]);

        $dele = $this->servico()->doUtilizador($comConta->utilizador()->id);
        $semDocente = $this->servico()->doUtilizador($this->criarProfessor('x@example.com')->id);

        $this->assertCount(1, $dele['entradas']);
        $this->assertSame('Português', $dele['entradas'][0]['disciplina_nome']);
        $this->assertSame([], $semDocente['entradas']);
    }

    public function test_por_sala(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c, ['sala_id' => $c['sala']->id]);
        $this->criarEntrada($c, ['dia_semana' => 2]);

        $r = $this->servico()->porSala($c['sala']->id);

        $this->assertCount(1, $r['entradas']);
        $this->assertSame(1, $r['entradas'][0]['dia_semana']);
    }

    public function test_data_mostra_a_versao_vigente_nessa_data(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01', 'vigencia_fim' => '2026-03-09']);
        $this->criarEntrada($c, ['disciplina_id' => $c['outraDisciplina']->id, 'vigencia_inicio' => '2026-03-10']);

        $antes = $this->servico()->porTurma($c['turma']->id, Carbon::parse('2026-03-05'));
        $depois = $this->servico()->porTurma($c['turma']->id, Carbon::parse('2026-03-12'));

        $this->assertSame('Matemática', $antes['entradas'][0]['disciplina_nome']);
        $this->assertSame('Português', $depois['entradas'][0]['disciplina_nome']);
    }

    public function test_disciplinas_da_turma_indicam_se_tem_titular(): void
    {
        $c = $this->cenarioDeHorario();
        AtribuicaoDocente::query()->where('disciplina_id', $c['outraDisciplina']->id)->delete();

        $r = $this->servico()->porTurma($c['turma']->id);
        $porNome = collect($r['disciplinas'])->keyBy('nome');

        $this->assertTrue($porNome['Matemática']['tem_titular']);
        $this->assertFalse($porNome['Português']['tem_titular']);
    }

    public function test_nao_mistura_tenants(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $r = $this->noTenant($outro, fn () => $this->servico()->porTurma($c['turma']->id));
        $s = $this->noTenant($outro, fn () => $this->servico()->porSala($c['sala']->id));

        $this->assertSame([], $r['entradas']);
        $this->assertSame([], $r['tempos']);
        $this->assertSame([], $s['entradas']);
    }

    public function test_entradas_em_tempos_fora_do_turno_actual_continuam_visiveis(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c);
        $tarde = Turno::create(['estabelecimento_id' => $c['turno']->estabelecimento_id, 'nome' => 'Tarde']);
        $this->criarTempo($tarde, '1º tempo tarde', '13:00', '13:45', 1);
        $c['turma']->update(['turno_id' => $tarde->id]);

        $r = $this->servico()->porTurma($c['turma']->id);

        $this->assertContains($c['tempo1']->id, array_column($r['tempos'], 'id'));
        $this->assertCount(1, $r['entradas']);
    }
}
