<?php

namespace Modules\Financeiro\DTO;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

final class RegistarPagamentoDTO
{
    public const REFERENCIA_MAXIMA = 100;

    public const OBSERVACAO_MAXIMA = 1000;

    public readonly ?string $referencia;

    public readonly ?string $observacao;

    /** @var list<LinhaDeDistribuicao>|null null = distribuição automática */
    public readonly ?array $distribuicao;

    public readonly Dinheiro $valorCredito;

    /**
     * @param  list<LinhaDeDistribuicao>|null  $distribuicao
     */
    public function __construct(
        public readonly int $metodoPagamentoId,
        public readonly Dinheiro $valor,
        public readonly CarbonImmutable $dataPagamento,
        ?string $referencia = null,
        ?string $observacao = null,
        ?array $distribuicao = null,
        ?Dinheiro $valorCredito = null,
        public readonly ?int $ate_ano = null,
        public readonly ?int $ate_mes = null,
        public readonly ?UploadedFile $comprovativo = null,
    ) {
        if ($valor->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('O valor do pagamento tem de ser maior que zero.');
        }

        if (($ate_ano === null) !== ($ate_mes === null)) {
            throw new InvalidArgumentException('O mês «até» exige o ano e o mês em conjunto.');
        }

        if ($ate_mes !== null && ($ate_mes < 1 || $ate_mes > 12)) {
            throw new InvalidArgumentException('O mês «até» tem de estar entre 1 e 12.');
        }

        $referencia = $referencia === null ? null : trim($referencia);
        $observacao = $observacao === null ? null : trim($observacao);

        if ($referencia !== null && mb_strlen($referencia) > self::REFERENCIA_MAXIMA) {
            throw new InvalidArgumentException('A referência tem no máximo ' . self::REFERENCIA_MAXIMA . ' caracteres.');
        }

        if ($observacao !== null && mb_strlen($observacao) > self::OBSERVACAO_MAXIMA) {
            throw new InvalidArgumentException('A observação tem no máximo ' . self::OBSERVACAO_MAXIMA . ' caracteres.');
        }

        $this->referencia = $referencia === '' ? null : $referencia;
        $this->observacao = $observacao === '' ? null : $observacao;
        $this->distribuicao = $distribuicao === null ? null : LinhaDeDistribuicao::lista($distribuicao);
        $this->valorCredito = $valorCredito ?? Dinheiro::deUnidadesMenores(0);
    }

    public static function fromRequest(FormRequest $request): self
    {
        $moeda = app(MoedaDoTenant::class)->atual();
        $dados = $request->validated();
        // '' / só espaços / ausente = não usar crédito (a regra do pedido pode deixá-lo passar como nullable).
        $credito = trim((string) ($dados['valor_credito'] ?? ''));

        return new self(
            metodoPagamentoId: (int) $dados['metodo_pagamento_id'],
            valor: Dinheiro::deDecimal((string) $dados['valor'], $moeda),
            dataPagamento: CarbonImmutable::createFromFormat('!Y-m-d', (string) $dados['data_pagamento']),
            referencia: $dados['referencia'] ?? null,
            observacao: $dados['observacao'] ?? null,
            distribuicao: LinhaDeDistribuicao::doPedido((string) $dados['modo_distribuicao'], $dados['distribuicao'] ?? [], $moeda),
            valorCredito: Dinheiro::deDecimal($credito === '' ? '0' : $credito, $moeda),
            ate_ano: isset($dados['ate_ano']) && $dados['ate_ano'] !== null ? (int) $dados['ate_ano'] : null,
            ate_mes: isset($dados['ate_mes']) && $dados['ate_mes'] !== null ? (int) $dados['ate_mes'] : null,
            comprovativo: $request->file('comprovativo'),
        );
    }
}
