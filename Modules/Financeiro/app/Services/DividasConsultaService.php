<?php

namespace Modules\Financeiro\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Models\Acordo;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Support\CacheDeTotais;
use Modules\Financeiro\Support\ProgressoDoAcordo;
use Modules\Matricula\Models\Matricula;

/**
 * Dívidas por matrícula: propinas Em Aberto ou Parcialmente Pagas de períodos já iniciados, multas por liquidar e
 * cobranças (produtos e serviços) em aberto. Agrega por matrícula em três consultas e junta em memória.
 */
class DividasConsultaService
{
    public function __construct(private RelogioDoTenant $relogio, private ProgressoDoAcordo $progresso, private CacheDeTotais $cache)
    {
    }

    /** @param array<string, mixed> $filtros pesquisa, ano_lectivo_id, turma_id, tipo (propinas|multas|cobrancas) */
    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        $linhas = $this->linhas($filtros);
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        return (new LengthAwarePaginator(
            $linhas->forPage($pagina, $porPagina)->values(),
            $linhas->count(),
            $porPagina,
            $pagina,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        ))->withQueryString();
    }

    /**
     * Totais para os cartões (carregados em diferido, à parte da lista).
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, int>
     */
    public function totais(array $filtros = []): array
    {
        $linhas = $this->linhas($filtros);

        return [
            'matriculas' => $linhas->count(),
            'propinas' => (int) $linhas->sum('propinas'),
            'multas' => (int) $linhas->sum('multas'),
            'cobrancas' => (int) $linhas->sum('cobrancas'),
            'total' => (int) $linhas->sum('total'),
        ];
    }

    /** @return Collection<int, array<string, mixed>> todas as linhas filtradas, da maior dívida para a menor */
    public function linhas(array $filtros = []): Collection
    {
        // Cache curto partilhado pela lista, pelos totais e pelos relatórios (invalidado por qualquer escrita financeira).
        return $this->cache->lembrar('dividas', $filtros, fn () => $this->calcularLinhas($filtros));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function calcularLinhas(array $filtros): Collection
    {
        $hoje = $this->relogio->hoje()->toDateString();

        $propinas = Propina::query()
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->where('periodo_inicio', '<=', $hoje)
            ->selectRaw('matricula_id, SUM(valor - valor_pago) as soma, COUNT(*) as meses, MIN(periodo_inicio) as desde')
            ->groupBy('matricula_id')->get()->keyBy('matricula_id');

        $multas = PropinaMulta::query()
            ->join('propinas', 'propinas.id', '=', 'propina_multas.propina_id')
            ->whereIn('propina_multas.estado', [EstadoMulta::ACTIVA->value, EstadoMulta::REDUZIDA->value])
            ->selectRaw('propinas.matricula_id as matricula_id, SUM(propina_multas.valor - propina_multas.valor_pago) as soma')
            ->groupBy('propinas.matricula_id')->get()->keyBy('matricula_id');

        $cobrancas = Cobranca::query()
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->selectRaw('matricula_id, SUM(total - valor_pago) as soma')
            ->groupBy('matricula_id')->get()->keyBy('matricula_id');

        $acordos = Acordo::query()->where('estado', EstadoAcordo::ACTIVO->value)->get()->keyBy('matricula_id')->map(function (Acordo $a) {
            $p = $this->progresso->de($a);

            return ['id' => $a->id, 'em_atraso' => $p['em_atraso'], 'proxima' => $p['proxima']];
        });

        $tipo = $filtros['tipo'] ?? '';
        $ids = $propinas->keys()->merge($multas->keys())->merge($cobrancas->keys())->unique()->values();
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';

        $matriculas = Matricula::withTrashed()
            ->with(['aluno.dadosPessoa', 'turma:id,nome,turno_id,ano_lectivo_id', 'turma.turno:id,nome', 'turma.anoLectivo:id,nome', 'anoLectivo:id,nome'])
            ->whereIn('id', $ids)
            ->when(is_numeric($filtros['ano_lectivo_id'] ?? null), fn ($q) => $q->where('ano_lectivo_id', (int) $filtros['ano_lectivo_id']))
            ->when(is_numeric($filtros['turma_id'] ?? null), fn ($q) => $q->where('turma_id', (int) $filtros['turma_id']))
            ->when($pesquisa !== '', fn ($q) => $q->where(fn ($g) => $g
                ->whereContem('numero_registo_matricula', $pesquisa)
                ->orWhereHas('aluno', fn ($a) => $a->where(fn ($x) => $x
                    ->whereContem('numero_matricula', $pesquisa)
                    ->orWhereHas('dadosPessoa', fn ($p) => $p->whereContem('nome_completo', $pesquisa))))))
            ->get();

        return $matriculas->map(function (Matricula $m) use ($propinas, $multas, $cobrancas, $tipo, $acordos) {
            $p = (int) ($propinas[$m->id]->soma ?? 0);
            $mu = (int) ($multas[$m->id]->soma ?? 0);
            $co = (int) ($cobrancas[$m->id]->soma ?? 0);

            return [
                'matricula_id' => $m->id,
                'aluno_id' => $m->aluno_id,
                'aluno_nome' => $m->aluno?->dadosPessoa?->nome_completo,
                'aluno_numero' => $m->aluno?->numero_matricula,
                'matricula_numero' => $m->numero_registo_matricula,
                'matricula_estado' => $m->estado?->label(),
                'turma_nome' => $m->turma?->nome,
                'turma_rotulo' => $m->turma?->rotulo(),
                'turma_rotulo_completo' => $m->turma?->rotulo(completo: true),
                'ano_lectivo_nome' => $m->anoLectivo?->nome,
                'propinas' => $p,
                'multas' => $mu,
                'cobrancas' => $co,
                'total' => $p + $mu + $co,
                'meses' => (int) ($propinas[$m->id]->meses ?? 0),
                'acordo' => $acordos[$m->id] ?? null,
                'desde' => isset($propinas[$m->id]) ? substr((string) $propinas[$m->id]->desde, 0, 10) : null,
            ];
        })
            ->filter(fn (array $l) => $l['total'] > 0 && match ($tipo) {
                'propinas' => $l['propinas'] > 0,
                'multas' => $l['multas'] > 0,
                'cobrancas' => $l['cobrancas'] > 0,
                default => true,
            })
            ->sortByDesc('total')
            ->values();
    }
}
