<?php

namespace Modules\Financeiro\Tests\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\DTO\AlterarPrecoPlanoDTO;
use Modules\Financeiro\DTO\PreVisualizacaoAlteracaoPrecoDTO;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Pedidos de alteração de preço (F4) para testes. Requer ComDadosAcademicosFinanceiro e ComPropinasFinanceiro.
 */
trait ComAlteracaoPreco
{
    protected function pedidoAlteracao(
        string $mesEfeito,
        int $valorNovo,
        ModoAlteracaoPreco $modo,
        ?string $motivo = null,
        ?string $impressaoDigital = null,
        bool $confirmaValoresDiferentes = false,
    ): AlterarPrecoPlanoDTO {
        return new AlterarPrecoPlanoDTO(
            valorNovo: Dinheiro::deUnidadesMenores($valorNovo),
            mesEfeito: CarbonImmutable::createFromFormat('!Y-m', $mesEfeito),
            modo: $modo,
            motivo: $motivo,
            impressaoDigital: $impressaoDigital,
            confirmaValoresDiferentes: $confirmaValoresDiferentes,
        );
    }

    protected function previa(PlanoPropina $plano, string $mesEfeito, int $valorNovo, ModoAlteracaoPreco $modo): PreVisualizacaoAlteracaoPrecoDTO
    {
        return app(AlteracaoPrecoPlanoService::class)->preVisualizar($plano->fresh(), $this->pedidoAlteracao($mesEfeito, $valorNovo, $modo));
    }

    /** Pré-visualiza e confirma com a impressão digital obtida (como a UI). */
    protected function alterarPreco(PlanoPropina $plano, string $mesEfeito, int $valorNovo, ModoAlteracaoPreco $modo, string $motivo = 'Actualização do preço', bool $confirma = true): AlteracaoPrecoPlano
    {
        $impressao = $this->previa($plano, $mesEfeito, $valorNovo, $modo)->impressaoDigital;

        return app(AlterarPrecoPlanoAction::class)->executar(
            $plano->fresh(),
            $this->pedidoAlteracao($mesEfeito, $valorNovo, $modo, $motivo, $impressao, $confirma),
        );
    }

    protected function assertRecusa(string $campo, string $texto, Closure $operacao): void
    {
        try {
            $operacao();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), 'Recusado noutro campo: ' . json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString($texto, $e->errors()[$campo][0]);

            return;
        }

        $this->fail("Devia recusar em «{$campo}».");
    }

    /**
     * Tudo o que F4 pode tocar, em cru, para provar "nada mudou".
     *
     * @return array<string, mixed>
     */
    protected function fotografia(): array
    {
        return [
            'planos' => PlanoPropina::query()->orderBy('id')->get()->map(fn (PlanoPropina $p) => $p->getAttributes())->all(),
            'alvos' => PlanoPropinaAlvo::query()->orderBy('id')->get()->map(fn (PlanoPropinaAlvo $a) => $a->getAttributes())->all(),
            'propinas' => Propina::query()->orderBy('id')->get()->map(fn (Propina $p) => $p->getAttributes())->all(),
            'alteracoes' => AlteracaoPrecoPlano::query()->count(),
            'ajustes' => PropinaAjuste::query()->count(),
        ];
    }
}
