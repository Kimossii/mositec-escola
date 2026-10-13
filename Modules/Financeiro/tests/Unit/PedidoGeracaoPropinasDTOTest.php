<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PedidoGeracaoPropinasDTOTest extends TestCase
{
    public function test_o_pedido_automatico_e_de_todas_as_matriculas_com_origem_comando(): void
    {
        $pedido = PedidoGeracaoPropinasDTO::automatica(5);

        $this->assertSame(5, $pedido->ano_lectivo_id);
        $this->assertSame(AmbitoGeracaoPropinas::TODAS, $pedido->ambito);
        $this->assertSame(OrigemGeracaoPropina::COMANDO, $pedido->origem);
        $this->assertNull($pedido->plano_propina_id);
        $this->assertFalse($pedido->incluir_periodos_anteriores);
        $this->assertNull($pedido->motivo_geracao);
    }

    public function test_os_ambitos_manuais_nao_incluem_todas(): void
    {
        $this->assertNotContains(AmbitoGeracaoPropinas::TODAS, AmbitoGeracaoPropinas::manuais());
        $this->assertCount(4, AmbitoGeracaoPropinas::manuais());
    }

    #[DataProvider('invalidos')]
    public function test_combinacoes_impossiveis_sao_recusadas(array $argumentos, string $mensagem): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($mensagem);

        new PedidoGeracaoPropinasDTO(...array_merge([
            'ano_lectivo_id' => 1,
            'ambito' => AmbitoGeracaoPropinas::TURMA,
            'origem' => OrigemGeracaoPropina::MANUAL,
            'alvo_id' => 3,
        ], $argumentos));
    }

    public static function invalidos(): array
    {
        return [
            'origem retroactiva no pedido' => [['origem' => OrigemGeracaoPropina::RETROACTIVA], 'por período'],
            'períodos anteriores sem motivo' => [['incluir_periodos_anteriores' => true, 'motivo_geracao' => '   '], 'motivo'],
            'períodos anteriores pelo comando' => [['origem' => OrigemGeracaoPropina::COMANDO, 'incluir_periodos_anteriores' => true, 'motivo_geracao' => 'x'], 'manual'],
            'turma sem turma' => [['alvo_id' => null], 'turma ou o nível'],
            'todas as do plano sem plano' => [['ambito' => AmbitoGeracaoPropinas::PLANO, 'alvo_id' => null], 'exige o plano'],
            'matrícula sem número' => [['ambito' => AmbitoGeracaoPropinas::MATRICULA, 'alvo_id' => null], 'número de registo'],
        ];
    }
}
