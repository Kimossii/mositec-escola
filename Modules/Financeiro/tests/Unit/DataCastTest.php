<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Financeiro\Casts\DataCast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DataCastTest extends TestCase
{
    private function modelo(): Model
    {
        return new class extends Model {
            protected $casts = ['dia' => DataCast::class];
        };
    }

    public function test_grava_sempre_ano_mes_dia_a_partir_de_texto_ou_de_data(): void
    {
        $modelo = $this->modelo();

        $modelo->dia = '2026-09-15';
        $this->assertSame('2026-09-15', $modelo->getAttributes()['dia']);

        $modelo->dia = CarbonImmutable::parse('2026-09-15 18:30:00');
        $this->assertSame('2026-09-15', $modelo->getAttributes()['dia']);

        $modelo->dia = Carbon::parse('2027-02-28 00:00:00');
        $this->assertSame('2027-02-28', $modelo->getAttributes()['dia']);
    }

    public function test_le_como_data_imutavel_a_meia_noite_mesmo_com_hora_gravada(): void
    {
        $modelo = $this->modelo();
        $modelo->setRawAttributes(['dia' => '2026-09-15 00:00:00']);

        $this->assertInstanceOf(CarbonImmutable::class, $modelo->dia);
        $this->assertSame('2026-09-15 00:00:00', $modelo->dia->toDateTimeString());
    }

    public function test_nulo_continua_nulo(): void
    {
        $modelo = $this->modelo();
        $modelo->dia = null;

        $this->assertNull($modelo->getAttributes()['dia']);
        $this->assertNull($modelo->dia);
    }

    #[DataProvider('invalidas')]
    public function test_valores_invalidos_sao_recusados(mixed $valor): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->modelo()->dia = $valor;
    }

    public static function invalidas(): array
    {
        return [
            '31 de Fevereiro' => ['2026-02-31'],
            'com hora' => ['2026-09-15 10:00:00'],
            'formato dia/mês/ano' => ['15/09/2026'],
            'inteiro' => [20260915],
            'quebra de linha no fim' => ["2026-09-15\n"],
        ];
    }

    public function test_serializa_como_ano_mes_dia(): void
    {
        $modelo = $this->modelo();
        $modelo->dia = '2026-09-15';

        $this->assertSame(['dia' => '2026-09-15'], $modelo->toArray());
    }
}
