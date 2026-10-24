<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Rules\ValorMonetario;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValorMonetarioTest extends TestCase
{
    use RefreshDatabase;

    private function passa(mixed $valor, bool $permiteZero = true): bool
    {
        return Validator::make(['v' => $valor], ['v' => ['required', new ValorMonetario($permiteZero)]])->passes();
    }

    private function mensagem(mixed $valor, bool $permiteZero = true): string
    {
        return Validator::make(['v' => $valor], ['v' => [new ValorMonetario($permiteZero)]])->errors()->first('v');
    }

    public function test_rejeita_inteiros_gigantes_sem_rebentar(): void
    {
        foreach ([1_000_000_000_000, PHP_INT_MAX, 99999999999999999999] as $valor) {
            $this->assertFalse($this->passa($valor), var_export($valor, true));
        }
    }

    #[DataProvider('aceitesEmAoa')]
    public function test_aoa_aceita_valores_validos(mixed $valor): void
    {
        $this->assertTrue($this->passa($valor));
    }

    public static function aceitesEmAoa(): array
    {
        return [
            'inteiro' => [25000],
            'texto inteiro' => ['25000'],
            'uma casa' => ['25000.5'],
            'virgula' => ['25000,50'],
            'float' => [25000.5],
            'zero texto' => ['0'],
            'zero inteiro' => [0],
        ];
    }

    #[DataProvider('rejeitadosEmAoa')]
    public function test_aoa_rejeita_valores_invalidos(mixed $valor): void
    {
        $this->assertFalse($this->passa($valor));
    }

    public static function rejeitadosEmAoa(): array
    {
        return [
            'negativo' => ['-1'],
            'negativo inteiro' => [-1],
            'letras' => ['abc'],
            'três casas' => ['12.345'],
            'milhares' => ['25.000,50'],
            'notação científica' => ['1e3'],
            'espaços' => ['25 000'],
            'quebra de linha final' => ["25000\n"],
            'lista' => [[1]],
            'demasiado grande' => ['1234567890123'],
        ];
    }

    public function test_sem_zero_rejeita_apenas_o_zero(): void
    {
        $this->assertFalse($this->passa('0', false));
        $this->assertFalse($this->passa(0, false));
        $this->assertFalse($this->passa('0,00', false));
        $this->assertTrue($this->passa('0,01', false));
        $this->assertTrue($this->passa('25000', false));
    }

    public function test_moeda_sem_casas_decimais_so_aceita_inteiros(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']);

        $this->assertTrue($this->passa('25000'));
        $this->assertTrue($this->passa(25000));
        $this->assertFalse($this->passa('25000,5'));
        $this->assertFalse($this->passa('25000.0'));
    }

    public function test_moeda_de_tres_casas_aceita_ate_tres(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'KWD']);

        $this->assertTrue($this->passa('25000,125'));
        $this->assertFalse($this->passa('25000,1255'));
    }

    public function test_mensagens_dizem_quantas_casas_a_moeda_tem(): void
    {
        $this->assertSame('O valor é inválido: use dígitos com até 2 casas decimais (ex.: 25000 ou 25000,50).', $this->mensagem('abc'));
        $this->assertSame('O valor tem de ser superior a zero.', $this->mensagem('0', false));

        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']);
        $this->assertSame('O valor é inválido: use apenas dígitos inteiros (ex.: 25000).', $this->mensagem('25000,5'));

        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'KWD']);
        $this->assertSame('O valor é inválido: use dígitos com até 3 casas decimais (ex.: 25000 ou 25000,500).', $this->mensagem('abc'));
    }
}
