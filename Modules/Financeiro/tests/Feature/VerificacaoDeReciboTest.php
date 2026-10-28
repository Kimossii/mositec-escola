<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Support\GeradorDeQr;
use Modules\Financeiro\Support\VerificacaoDeRecibo;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class VerificacaoDeReciboTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function pagamento()
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 1);

        return $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]);
    }

    public function test_o_recibo_traz_o_qr_e_o_endereco_de_verificacao_assinado(): void
    {
        $pagamento = $this->pagamento();
        $token = app(VerificacaoDeRecibo::class)->token($pagamento);

        $this->actingAs($this->adminEscola())->get("/financeiro/pagamentos/{$pagamento->id}/recibo")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('verificacao.url', fn ($url) => str_ends_with($url, "/verificar-recibo/{$token}"))
                ->where('verificacao.qr', fn ($svg) => str_starts_with($svg, '<svg')));
    }

    public function test_a_pagina_publica_confirma_o_recibo_sem_login_e_so_mostra_o_minimo(): void
    {
        $pagamento = $this->pagamento();
        $token = app(VerificacaoDeRecibo::class)->token($pagamento);

        $this->get("/verificar-recibo/{$token}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Financeiro/Recibos/Verificar')
            ->where('recibo.numero', $pagamento->numero)
            ->where('recibo.valor', 2_500_000)
            ->where('recibo.anulado', false)
            ->where('recibo.aluno', fn ($nome) => preg_match('/^\S+( \S\.)?$/u', $nome) === 1) // nome abreviado
            ->missing('recibo.matricula')
            ->missing('recibo.email'));
    }

    public function test_recibo_anulado_aparece_como_anulado(): void
    {
        $pagamento = $this->pagamento();
        $this->actingAs($this->adminEscola());
        $this->anularPagamento($pagamento);
        auth()->logout();

        $this->get('/verificar-recibo/'.app(VerificacaoDeRecibo::class)->token($pagamento))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recibo.anulado', true));
    }

    public function test_token_forjado_ou_adulterado_nao_encontra_nada(): void
    {
        $pagamento = $this->pagamento();
        $token = app(VerificacaoDeRecibo::class)->token($pagamento);
        [$id] = explode('-', $token);

        foreach (["{$id}-".str_repeat('0', 20), "{$id}-abc", 'lixo', ($id + 1).'-'.explode('-', $token)[1], '9999999-'.str_repeat('a', 20)] as $invalido) {
            $this->get("/verificar-recibo/{$invalido}")->assertNotFound()
                ->assertInertia(fn (Assert $page) => $page->where('recibo', null));
        }
    }

    public function test_gerador_de_qr_devolve_svg_sem_prologo_xml(): void
    {
        $svg = GeradorDeQr::svg('https://escola.test/verificar-recibo/1-abc');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
    }
}
