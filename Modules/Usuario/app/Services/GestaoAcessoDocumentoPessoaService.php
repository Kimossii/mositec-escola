<?php

namespace Modules\Usuario\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Usuario\Models\AcessoDocumentoPessoa;

class GestaoAcessoDocumentoPessoaService
{
    /** @param array{dados_pessoa_id?: ?int, user_id?: ?int, pessoa?: ?string, utilizador?: ?string, acao?: ?string, de?: ?string, ate?: ?string} $filtros */
    public function listar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        return AcessoDocumentoPessoa::query()
            ->with(['utilizador:id,name', 'dadosPessoa:id,nome_completo', 'documento:id,nome_original'])
            ->when($filtros['dados_pessoa_id'] ?? null, fn ($q, $v) => $q->where('dados_pessoa_id', $v))
            ->when($filtros['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filtros['pessoa'] ?? null, fn ($q, $v) => $q->whereHas('dadosPessoa', fn ($p) => $p->where('nome_completo', 'like', '%'.addcslashes($v, '%_\\').'%')))
            ->when($filtros['utilizador'] ?? null, fn ($q, $v) => $q->whereHas('utilizador', fn ($u) => $u->where('name', 'like', '%'.addcslashes($v, '%_\\').'%')))
            ->when($filtros['acao'] ?? null, fn ($q, $v) => $q->where('acao', $v))
            ->when($filtros['de'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['ate'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('created_at')
            ->latest('id')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (AcessoDocumentoPessoa $a) => [
                'id' => $a->id,
                'quando' => $a->created_at?->format('Y-m-d H:i:s'),
                'utilizador' => $a->utilizador?->name ?? $a->utilizador_nome,
                'pessoa' => $a->dadosPessoa?->nome_completo ?? $a->pessoa_nome,
                'documento' => $a->documento?->nome_original,
                'acao' => $a->acao_descricao,
                'acao_codigo' => $a->acao->value,
                'ip' => $a->ip,
            ]);
    }
}
