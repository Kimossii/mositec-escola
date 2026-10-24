<?php

namespace App\Services;

use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Matricula\Models\Matricula;
use Modules\Usuario\Models\User;

/**
 * Pesquisa global do cabeçalho: poucos resultados por grupo, só dos módulos que o utilizador pode ver, reaproveitando
 * as pesquisas das listas. Cada resultado traz título, subtítulo (contexto), um extra opcional e a ligação.
 */
class PesquisaGlobalService
{
    public const MINIMO = 2;

    private const POR_GRUPO = 5;

    public function __construct(
        private AlunoConsultaService $alunos,
        private PagamentoConsultaService $pagamentos,
        private MoedaDoTenant $moeda,
    ) {
    }

    /** @return array<string, list<array{titulo: string, subtitulo: string, extra: string|null, link: string}>> */
    public function pesquisar(User $user, string $termo): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < self::MINIMO) {
            return [];
        }

        $grupos = [];

        if ($user->can('aluno.ver')) {
            $grupos['Alunos'] = $this->alunos->listar(['pesquisa' => $termo], self::POR_GRUPO)->getCollection()
                ->map(fn (Aluno $a) => [
                    'titulo' => $a->dadosPessoa?->nome_completo ?? '—',
                    'subtitulo' => 'Aluno nº '.$a->numero_matricula,
                    'extra' => null,
                    'link' => "/alunos/{$a->id}",
                ])->all();
        }

        if ($user->can('matricula.ver')) {
            $grupos['Matrículas'] = Matricula::query()
                ->with(['aluno.dadosPessoa', 'turma:id,nome', 'anoLectivo:id,nome'])
                ->whereContem('numero_registo_matricula', $termo)
                ->orderByDesc('data_matricula')->limit(self::POR_GRUPO)->get()
                ->map(fn (Matricula $m) => [
                    'titulo' => 'Matrícula '.$m->numero_registo_matricula,
                    'subtitulo' => ($m->aluno?->dadosPessoa?->nome_completo ?? '—').' · '.($m->turma?->nome ?? '—').' · '.($m->anoLectivo?->nome ?? '—'),
                    'extra' => $m->estado?->label(),
                    'link' => "/alunos/{$m->aluno_id}",
                ])->all();
        }

        if ($user->can('pagamento.ver')) {
            $grupos['Pagamentos'] = $this->pagamentos->listar(['pesquisa' => $termo], self::POR_GRUPO)->getCollection()
                ->map(fn (array $p) => [
                    'titulo' => 'Pagamento '.$p['numero'],
                    'subtitulo' => ($p['aluno_nome'] ?? '—').' · '.date('d/m/Y', strtotime($p['data_pagamento'])),
                    'extra' => Dinheiro::deUnidadesMenores($p['valor'])->formatar($this->moeda->atual()),
                    'link' => '/financeiro/pagamentos?pesquisa='.urlencode($p['numero']),
                ])->all();
        }

        return array_filter($grupos);
    }
}
