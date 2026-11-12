<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Avaliacao\Enums\TratamentoDeNegativas;
use Modules\Avaliacao\Models\AvaliacaoNegativaTratada;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Tests\TestCase;

class PautaFinalServiceTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function regra(array $c, string $minima = '5'): void
    {
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, [$c['nivel']->id], 10, $minima));
    }

    private function pauta(array $c): array
    {
        return app(PautaFinalService::class)->paraTurma($c['turma']->id);
    }

    public function test_aluno_com_tudo_positivo_transita_e_a_pauta_pode_ser_aprovada(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        $this->notasCompletas($c, $this->inscreverAluno($c, 'Ana'), [1 => ['5.33', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);

        $p = $this->pauta($c);

        $this->assertSame('provisoria', $p['estado']);
        $this->assertTrue($p['pode_aprovar']);
        $this->assertSame([], $p['bloqueios']);
        $celula = $p['alunos'][0]['celulas'][$c['disciplina']->id];
        $this->assertSame(['6.17', '6.50', '8.50'], [$celula['mt1'], $celula['mt2'], $celula['mt3']]);
        $this->assertSame('7.06', $celula['mfd']);
        $this->assertTrue($celula['aprovado']);
        $this->assertSame(['valor' => 0, 'rotulo' => 'Transita'], $p['alunos'][0]['decisao']);
    }

    public function test_uma_disciplina_negativa_impede_a_transicao_sem_compensacao(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        $c2 = $this->outraDisciplina($c);
        $inscricao = $this->inscreverAluno($c, 'Rui');
        $this->notasCompletas($c, $inscricao, [1 => ['9', '9'], 2 => ['9', '9'], 3 => ['9', '9']]);
        $this->notasCompletas($c2, $this->inscreverNaDisciplina($inscricao, $c2), [1 => ['2', '2'], 2 => ['2', '2'], 3 => ['2', '2']]);

        $p = $this->pauta($c);

        $this->assertCount(2, $p['disciplinas']);
        $this->assertSame('Não transita', $p['alunos'][0]['decisao']['rotulo']);
        $this->assertTrue($p['pode_aprovar']);
    }

    public function test_decide_sobre_o_valor_arredondado_impresso(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        // MT1 = MT2 = MT3 = 4,6 → MFD 4,6 → impresso 4,60 com 2 casas: abaixo de 5, não transita
        $this->notasCompletas($c, $this->inscreverAluno($c), [1 => ['4.6', '4.6'], 2 => ['4.6', '4.6'], 3 => ['4.6', '4.6']]);

        $this->assertSame('Não transita', $this->pauta($c)['alunos'][0]['decisao']['rotulo']);
    }

    public function test_sem_os_tres_trimestres_ha_pendentes_e_nao_se_pode_aprovar(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->regra($c);
        $this->notasCompletas($c, $this->inscreverAluno($c), [1 => ['8', '8']]);

        $p = $this->pauta($c);

        $this->assertFalse($p['pode_aprovar']);
        $this->assertStringContainsString('Faltam resultados em 1 aluno', $p['bloqueios'][0]);
        $this->assertNull($p['alunos'][0]['decisao']);
        $this->assertSame('pendente', $p['alunos'][0]['estado_decisao']);
        $this->assertNull($p['alunos'][0]['celulas'][$c['disciplina']->id]['mfd']);
    }

    public function test_sem_alunos_nao_se_aprova(): void
    {
        $c = $this->cenarioDeAvaliacao();

        $this->regra($c);
        $p = $this->pauta($c);
        $this->assertFalse($p['pode_aprovar']);
        $this->assertSame('A turma não tem alunos com matrícula activa.', $p['bloqueios'][0]);
    }

    public function test_1_e_2_classes_transitam_mesmo_com_notas_baixas_depois_de_tratadas_as_negativas(): void
    {
        $c = $this->cenarioDeAvaliacao('PRI_1');
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, [$c['nivel']->id], 10, '5', tratamento_negativas: TratamentoDeNegativas::TRANSITA_TRATANDO_NEGATIVAS));
        $inscricao = $this->inscreverAluno($c);
        $this->notasCompletas($c, $inscricao, [1 => ['1', '1'], 2 => ['1', '1'], 3 => ['1', '1']]);
        AvaliacaoNegativaTratada::create(['inscricao_disciplina_id' => $inscricao->id, 'medida' => 'Apoio.']);

        $this->assertSame('Transita', $this->pauta($c)['alunos'][0]['decisao']['rotulo']);
    }

    public function test_onde_o_aluno_tem_de_transitar_a_pauta_so_se_aprova_com_as_negativas_tratadas(): void
    {
        $c = $this->cenarioDeAvaliacao('PRI_1');
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, [$c['nivel']->id], 10, '5', tratamento_negativas: TratamentoDeNegativas::TRANSITA_TRATANDO_NEGATIVAS));
        $inscricao = $this->inscreverAluno($c);
        $this->notasCompletas($c, $inscricao, [1 => ['1', '1'], 2 => ['1', '1'], 3 => ['1', '1']]);

        $p = $this->pauta($c);
        $this->assertNull($p['alunos'][0]['decisao']); // com a negativa por tratar, não transita no papel
        $this->assertSame([['disciplina' => $c['disciplina']->nome, 'inscricao_id' => $inscricao->id, 'medida' => null]], $p['alunos'][0]['negativas']);
        $this->assertFalse($p['pode_aprovar']);
        $this->assertStringContainsString('Faltam tratar as negativas de 1 aluno(s)', implode(' ', $p['bloqueios']));

        AvaliacaoNegativaTratada::create(['inscricao_disciplina_id' => $inscricao->id, 'medida' => 'Aulas de apoio às terças.']);

        $p = $this->pauta($c);
        $this->assertSame('Transita', $p['alunos'][0]['decisao']['rotulo']);
        $this->assertSame('Aulas de apoio às terças.', $p['alunos'][0]['negativas'][0]['medida']);
        $this->assertTrue($p['pode_aprovar']);
    }
}
