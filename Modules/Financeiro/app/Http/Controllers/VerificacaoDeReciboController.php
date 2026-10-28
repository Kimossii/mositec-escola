<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Moeda;
use Modules\Financeiro\Support\VerificacaoDeRecibo;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Página PÚBLICA (sem login) para quem lê o QR de um recibo: confirma que é autêntico e mostra o mínimo necessário. */
class VerificacaoDeReciboController extends Controller
{
    public function __construct(private VerificacaoDeRecibo $verificacao)
    {
    }

    public function show(Request $request, string $token)
    {
        $escola = Estabelecimento::query()->first();
        $pagamento = $this->verificacao->pagamentoDoToken($token);

        $resposta = Inertia::render('Financeiro/Recibos/Verificar', [
            'escola' => ['nome' => $escola?->nome, 'logotipo_url' => $escola?->logotipo_url],
            'recibo' => $pagamento === null ? null : $this->resumo($pagamento),
        ])->rootView('layouts.guest')->toResponse($request);

        // Um token inválido responde 404 (a página explica que o recibo não foi encontrado).
        return $pagamento === null ? $resposta->setStatusCode(404) : $resposta;
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
