<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use Modules\Financeiro\Support\ElegibilidadeDePeriodos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ElegibilidadeDePeriodosTest extends TestCase
{
    public function test_o_inicio_e_o_primeiro_dia_do_mes_da_matricula_a_meia_noite(): void
    {
        $elegibilidade = new ElegibilidadeDePeriodos();

        $this->assertSame('2026-11-01 00:00:00', $elegibilidade->inicio(CarbonImmutable::parse('2026-11-15 18:45:00'))->toDateTimeString());
        $this->assertSame('2026-11-01 00:00:00', $elegibilidade->inicio(CarbonImmutable::parse('2026-11-01'))->toDateTimeString());
    }

    #[DataProvider('casos')]
    public function test_um_periodo_e_anterior_a_matricula_quando_acaba_antes_do_mes_dela(string $dataMatricula, string $fimDoPeriodo, bool $anterior): void
    {
        $this->assertSame($anterior, (new ElegibilidadeDePeriodos())->anteriorAMatricula(CarbonImmutable::parse($dataMatricula), $fimDoPeriodo));
    }

    public static function casos(): array
    {
        return [
            'mês anterior ao da matrícula' => ['2026-11-15', '2026-10-31', true],
            'o próprio mês da matrícula' => ['2026-11-15', '2026-11-30', false],
            'matrícula no dia 1' => ['2026-11-01', '2026-11-30', false],
            'trimestre Set–Nov em curso com matrícula a meio de Outubro' => ['2026-10-15', '2026-11-30', false],
            'trimestre Set–Nov já acabado' => ['2026-12-02', '2026-11-30', true],
            'atravessa o ano civil' => ['2027-01-31', '2026-12-31', true],
            'período futuro' => ['2026-09-01', '2027-06-30', false],
        ];
    }
}
