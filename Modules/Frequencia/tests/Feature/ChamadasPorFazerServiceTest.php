<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Contracts\ConsultaChamadasPendentes;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class ChamadasPorFazerServiceTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contrato(): ConsultaChamadasPendentes
    {
        return app(ConsultaChamadasPendentes::class);
    }

    public function test_aula_sem_chamada_conta_e_com_chamada_ou_nao_leccionada_nao(): void
    {
        Carbon::setTestNow('2026-03-04 10:00:00'); // quarta-feira
        $c = $this->cenarioDeFrequencia(); // segunda, tempo 1
        $this->criarEntrada($c, ['dia_semana' => 3, 'vigencia_inicio' => '2026-01-01']); // quarta, tempo 1: é hoje
        $titular = $this->utilizadorDoDocente($c['docente']);

        $this->assertSame(2, $this->contrato()->doUtilizador($titular->id)); // segunda 02/03 e quarta 04/03

        $this->aulaComRegistos($c, '2026-03-02');
        $this->assertSame(1, $this->contrato()->doUtilizador($titular->id));

        $this->aulaComRegistos($c, '2026-03-04', [], ['estado' => EstadoAula::NAO_LECCIONADA, 'motivo_nao_leccionada' => 'Greve']);
        $this->assertSame(0, $this->contrato()->doUtilizador($titular->id));
    }

    public function test_so_ultimos_7_dias_ate_hoje(): void
    {
        Carbon::setTestNow('2026-03-11 10:00:00'); // quarta-feira
        $c = $this->cenarioDeFrequencia();
        $titular = $this->utilizadorDoDocente($c['docente']);

        // Segundas-feiras: 02/03 (há 9 dias, fora), 09/03 (dentro), 16/03 (futuro).
        $this->assertSame(1, $this->contrato()->doUtilizador($titular->id));
    }

    public function test_da_escola_conta_todas_as_turmas_e_do_utilizador_so_as_dele(): void
    {
        Carbon::setTestNow('2026-03-04 10:00:00');
        $c = $this->cenarioDeFrequencia();
        $turmaB = $c['turma']->replicate(['codigo', 'nome']);
        $turmaB->codigo = 'T10B';
        $turmaB->nome = 'Turma 10B';
        $turmaB->save();
        $cB = ['turma' => $turmaB] + $c;
        $docenteB = $this->criarDocente('Docente B', comAcesso: true);
        $this->atribuirTitular($cB, $docenteB, $c['disciplina']->id);
        $this->criarEntrada($cB, ['vigencia_inicio' => '2026-01-01']);

        $this->assertSame(2, $this->contrato()->daEscola());
        $this->assertSame(1, $this->contrato()->doUtilizador($this->utilizadorDoDocente($c['docente'])->id));
        $this->assertSame(1, $this->contrato()->doUtilizador($docenteB->utilizador()->id));
        $this->assertSame(0, $this->contrato()->doUtilizador($this->criarProfessor('sem@example.com')->id));
    }

    public function test_substituto_novo_nao_ve_aulas_de_antes_da_sua_atribuicao(): void
    {
        Carbon::setTestNow('2026-03-11 10:00:00'); // quarta; a segunda 09/03 está na janela
        $c = $this->cenarioDeFrequencia();
        $subst = $this->criarDocente('Substituto Novo', comAcesso: true);
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $subst->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-03-10',
        ]);

        $this->assertSame(0, $this->contrato()->doUtilizador($subst->utilizador()->id));
        $this->assertSame(1, $this->contrato()->doUtilizador($this->utilizadorDoDocente($c['docente'])->id));
    }

    public function test_titular_cuja_atribuicao_acabou_ainda_deve_as_chamadas_do_seu_tempo(): void
    {
        Carbon::setTestNow('2026-03-11 10:00:00');
        $c = $this->cenarioDeFrequencia();
        AtribuicaoDocente::query()->where('disciplina_id', $c['disciplina']->id)->update(['data_fim' => '2026-03-10']);

        $this->assertSame(1, $this->contrato()->doUtilizador($this->utilizadorDoDocente($c['docente'])->id));
    }
}
