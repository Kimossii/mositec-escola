<?php

namespace Modules\Avaliacao\Tests\Unit;

use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\TratamentoDeNegativas;
use Modules\Avaliacao\Services\RegraPadraoService;
use Modules\Avaliacao\Support\Calculo\DecisaoFinal;
use PHPUnit\Framework\TestCase;

class DecisaoFinalTest extends TestCase
{
    private function decidir(array $notas, TratamentoDeNegativas $tratamento = TratamentoDeNegativas::REPROVA, bool $exame = false): ?Decisao
    {
        return (new DecisaoFinal(new \Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa()))->decidir($notas, '5', $tratamento, $exame);
    }

    public function test_transita_com_todas_as_disciplinas_positivas_incluindo_o_minimo_exacto(): void
    {
        $this->assertSame(Decisao::TRANSITA, $this->decidir(['5.00', '7.50', '10.00']));
    }

    public function test_uma_disciplina_negativa_impede_a_transicao_sem_compensacao(): void
    {
        $this->assertSame(Decisao::NAO_TRANSITA, $this->decidir(['4.99', '10.00', '10.00']));
    }

    public function test_decide_sobre_o_valor_arredondado_recebido(): void
    {
        $this->assertSame(Decisao::TRANSITA, $this->decidir(['5.00'])); // 4,6 arredondado a 5 já chega como '5'
        $this->assertSame(Decisao::NAO_TRANSITA, $this->decidir(['4.00']));
    }

    public function test_so_o_tratamento_reprova_decide_pelas_negativas(): void
    {
        $t = TratamentoDeNegativas::TRANSITA_TRATANDO_NEGATIVAS;
        $this->assertSame(Decisao::TRANSITA, $this->decidir(['1.00', '2.00'], $t));
        $this->assertSame(Decisao::APTO, $this->decidir(['1.00', '2.00'], $t, exame: true));
    }

    public function test_disciplina_sem_mfd_deixa_a_decisao_em_aberto(): void
    {
        $this->assertNull($this->decidir(['8.00', null]));
        $this->assertNull($this->decidir([]));
    }

    public function test_classe_com_exame_usa_os_termos_apto_e_nao_apto(): void
    {
        $this->assertSame(Decisao::APTO, $this->decidir(['8.00', '5.00'], exame: true));
        $this->assertSame(Decisao::NAO_APTO, $this->decidir(['8.00', '4.99'], exame: true));
        $this->assertNull($this->decidir(['8.00', null], exame: true));
    }

    public function test_o_enum_tem_os_cinco_termos(): void
    {
        $this->assertSame(['Transita', 'Não transita', 'Apto', 'Não apto', 'Recurso'], array_map(fn (Decisao $d) => $d->label(), Decisao::cases()));
    }

    public function test_so_a_6_e_a_9_classes_tem_exame_proprio(): void
    {
        $s = new RegraPadraoService();

        $this->assertTrue($s->temExameProprio('PRI_6'));
        $this->assertTrue($s->temExameProprio('SEC_9'));
        $this->assertFalse($s->temExameProprio('PRI_5'));
        $this->assertFalse($s->temExameProprio('SEC_8'));
    }
}
