<?php

namespace Modules\Financeiro\Services;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Support\Moeda;
use Modules\Financeiro\Support\VerificacaoDeRecibo;

/** O que a página pública de verificação de um recibo mostra: a escola e o mínimo que confirma o recibo. */
class VerificacaoDeReciboService
{
    public function __construct(private VerificacaoDeRecibo $verificacao)
    {
    }

    /**
     * @return array{props: array<string, mixed>, encontrado: bool} `encontrado` é falso para um token inválido (a página responde 404)
     */
    public function pagina(string $token): array
    {
        $escola = Estabelecimento::query()->first();
        $pagamento = $this->verificacao->pagamentoDoToken($token);

        return [
            'props' => [
                'escola' => ['nome' => $escola?->nome, 'logotipo_url' => $escola?->logotipo_url],
                'recibo' => $pagamento === null ? null : $this->resumo($pagamento),
            ],
            'encontrado' => $pagamento !== null,
        ];
    }

    /** @return array<string, mixed> só o que confirma o recibo: nada de contactos, identificação nem matrícula do aluno */
    private function resumo(Pagamento $pagamento): array
    {
        $nome = trim((string) $pagamento->matricula?->aluno?->dadosPessoa?->nome_completo);
        $partes = preg_split('/\s+/', $nome, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $abreviado = count($partes) > 1 ? $partes[0].' '.mb_substr(end($partes), 0, 1).'.' : ($partes[0] ?? null);

        return [
            'numero' => $pagamento->numero,
            'data_pagamento' => $pagamento->data_pagamento?->toDateString(),
            'valor' => $pagamento->valor->unidadesMenores(),
            'moeda' => Moeda::de($pagamento->moeda)->paraFrontend(),
            'aluno' => $abreviado,
            'anulado' => $pagamento->estado === EstadoPagamento::ANULADO,
            'anulado_em' => $pagamento->anulado_em?->toDateString(),
        ];
    }
}
