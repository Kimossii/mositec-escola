<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\LinhaDeDistribuicao;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Services\MoedaDoTenant;
use Tests\TestCase;

/**
 * Entrada vinda do pedido HTTP: o que a validação do pedido (Task 13) deixar passar não pode rebentar em 500.
 */
class PedidoDePagamentoDtoTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $dados */
    private function pedido(array $dados): FormRequest
    {
        return new class($dados) extends FormRequest {
            /** @param array<string, mixed> $dados */
            public function __construct(private array $dados)
            {
                parent::__construct();
            }

            public function validated($key = null, $default = null)
            {
                return $this->dados;
            }
        };
    }

    /** @return array<string, mixed> */
    private function base(array $extra = []): array
    {
        return array_merge([
            'metodo_pagamento_id' => 1,
            'valor' => '1000,00',
            'data_pagamento' => '2026-10-16',
            'modo_distribuicao' => 'automatica',
        ], $extra);
    }

    public function test_valor_credito_vazio_ou_nulo_conta_como_zero(): void
    {
        foreach (['', '  ', null] as $vazio) {
            $dto = RegistarPagamentoDTO::fromRequest($this->pedido($this->base(['valor_credito' => $vazio])));

            $this->assertSame(0, $dto->valorCredito->unidadesMenores(), var_export($vazio, true));
        }

        $this->assertSame(0, RegistarPagamentoDTO::fromRequest($this->pedido($this->base()))->valorCredito->unidadesMenores());
    }

    public function test_valor_credito_preenchido_continua_a_ser_lido(): void
    {
        $dto = RegistarPagamentoDTO::fromRequest($this->pedido($this->base(['valor_credito' => '250,00'])));

        $this->assertSame(25_000, $dto->valorCredito->unidadesMenores());
    }

    public function test_linha_que_nao_e_um_array_e_erro_de_validacao_e_nao_type_error(): void
    {
        $moeda = app(MoedaDoTenant::class)->atual();

        foreach (['texto', 5, null, true] as $linhaInvalida) {
            try {
                LinhaDeDistribuicao::doPedido(LinhaDeDistribuicao::EXPLICITA, [['propina_id' => 1, 'valor' => '10,00'], $linhaInvalida], $moeda);
                $this->fail('Devia ser erro de validação para ' . var_export($linhaInvalida, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('distribuicao.1', $e->errors());
            }
        }
    }

    public function test_linha_sem_propina_ou_sem_valor_e_erro_de_validacao(): void
    {
        $moeda = app(MoedaDoTenant::class)->atual();

        foreach ([['valor' => '10,00'], ['propina_id' => 3], []] as $linha) {
            try {
                LinhaDeDistribuicao::doPedido(LinhaDeDistribuicao::EXPLICITA, [$linha], $moeda);
                $this->fail('Devia ser erro de validação para ' . json_encode($linha));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('distribuicao.0', $e->errors());
            }
        }
    }

    public function test_distribuicao_explicita_que_nao_e_lista_e_erro_e_ausente_e_lista_vazia(): void
    {
        $moeda = app(MoedaDoTenant::class)->atual();

        $this->assertSame([], LinhaDeDistribuicao::doPedido(LinhaDeDistribuicao::EXPLICITA, null, $moeda), 'Sem linhas: tudo crédito.');
        $this->assertNull(LinhaDeDistribuicao::doPedido(LinhaDeDistribuicao::AUTOMATICA, 'lixo', $moeda));

        $this->expectException(ValidationException::class);
        LinhaDeDistribuicao::doPedido(LinhaDeDistribuicao::EXPLICITA, 'lixo', $moeda);
    }

    public function test_linhas_validas_continuam_a_funcionar(): void
    {
        $linhas = LinhaDeDistribuicao::doPedido(LinhaDeDistribuicao::EXPLICITA, [['propina_id' => '7', 'valor' => '25,00']], app(MoedaDoTenant::class)->atual());

        $this->assertSame([7], array_map(fn (LinhaDeDistribuicao $l) => $l->propinaId, $linhas));
        $this->assertSame(2_500, $linhas[0]->valor->unidadesMenores());
    }
}
