<?php

namespace App\Http\Controllers;

use App\Services\PesquisaGlobalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PesquisaController extends Controller
{
    public function index(Request $request, PesquisaGlobalService $pesquisa): JsonResponse
    {
        $dados = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['grupos' => (object) $pesquisa->pesquisar($request->user(), $dados['q'] ?? '')]);
    }
}
