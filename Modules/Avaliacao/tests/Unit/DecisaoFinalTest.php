<?php

namespace Modules\Avaliacao\Tests\Unit;

use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Services\RegraPadraoService;
use Modules\Avaliacao\Support\Calculo\DecisaoFinal;
use PHPUnit\Framework\TestCase;

class DecisaoFinalTest extends TestCase
{
    private function decidir(array $notas, bool $reprova = true, bool $exame = false): ?Decisao
    {
        return (new DecisaoFinal(new \Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa()))->decidir($notas, '5', $reprova, $exame);
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

    public function test_sem_reprovacao_por_aproveitamento_transita_sempre(): void
    {
        $this->assertSame(Decisao::TRANSITA, $this->decidir(['1.00', '2.00'], reprova: false));
    }

    public function test_disciplina_sem_mfd_deixa_a_decisao_em_aberto(): void
    {
        $this->assertNull($this->decidir(['8.00', null]));
        $this->assertNull($this->decidir([]));
    }

    public function test_classe_com_exame_aguarda_o_exame(): void
    {
        $this->assertNull($this->decidir(['8.00', '9.00'], exame: true));
    }

    public function test_o_enum_tem_os_quatro_termos(): void
    {
        $this->assertSame(['Transita', 'Não transita', 'Apto', 'Não apto'], array_map(fn (Decisao $d) => $d->label(), Decisao::cases()));
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
