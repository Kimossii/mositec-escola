<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PagamentoConsultaService;

/** Lista de recibos: um recibo por pagamento (o número é o do pagamento). */
class ReciboController extends Controller
{
    public function __construct(private PagamentoConsultaService $consulta, private MoedaDoTenant $moeda)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('pagamento.ver');

        $filtros = $request->only(['pesquisa', 'metodo_pagamento_id', 'estado', 'data_de', 'data_ate']);

        return Inertia::render('Financeiro/Recibos/Index', [
            'recibos' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'metodos' => $this->consulta->metodos(),
            'estados' => $this->consulta->estados(),
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }
}
