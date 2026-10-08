<?php

namespace Modules\Financeiro\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\TestCase;

class DinheiroCastTest extends TestCase
{
    private function modelo(): Model
    {
        return new class extends Model {};
    }

    public function test_get_devolve_dinheiro(): void
    {
        $valor = (new DinheiroCast())->get($this->modelo(), 'valor', '2500000', []);

        $this->assertInstanceOf(Dinheiro::class, $valor);
        $this->assertSame(2_500_000, $valor->centimos());
    }

    public function test_get_e_set_aceitam_null(): void
    {
        $cast = new DinheiroCast();

        $this->assertNull($cast->get($this->modelo(), 'valor', null, []));
        $this->assertNull($cast->set($this->modelo(), 'valor', null, []));
    }

    public function test_set_aceita_dinheiro_e_inteiro(): void
    {
        $cast = new DinheiroCast();

        $this->assertSame(500, $cast->set($this->modelo(), 'valor', Dinheiro::deCentimos(500), []));
        $this->assertSame(500, $cast->set($this->modelo(), 'valor', 500, []));
    }

    public function test_set_rejeita_float_string_e_negativo(): void
    {
        $cast = new DinheiroCast();

        foreach ([12.5, '12', -1] as $invalido) {
            try {
                $cast->set($this->modelo(), 'valor', $invalido, []);
                $this->fail('Devia rejeitar ' . var_export($invalido, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_serializacao_emite_o_inteiro_em_centimos(): void
    {
        $modelo = new class extends Model {
            protected $casts = ['valor' => DinheiroCast::class];
        };
        $modelo->valor = Dinheiro::deCentimos(2_500_000);

        $this->assertSame(2_500_000, $modelo->toArray()['valor']);
        $this->assertStringContainsString('"valor":2500000', json_encode($modelo));

        $modelo->valor = null;
        $this->assertNull($modelo->toArray()['valor']);
    }
}
