<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Models\Acordo;
use Modules\Financeiro\Models\AcordoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\CacheDeTotais;
use Modules\Financeiro\Support\ProgressoDoAcordo;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Matricula\Models\Matricula;

class AcordoConsultaService
{
    public function __construct(private ProgressoDoAcordo $progresso, private CacheDeTotais $totais)
    {
    }

    /**
     * Números para os cartões: activos, em atraso, cumpridos e quanto já se recuperou e falta. Unidades menores.
     *
     * @return array<string, int>
     */
    public function indicadores(): array
    {
        return $this->totais->lembrar('acordos', [], function () {
            $r = ['activos' => 0, 'em_atraso' => 0, 'cumpridos' => 0, 'pago' => 0, 'em_falta' => 0, 'cancelados' => Acordo::query()->where('estado', '!=', EstadoAcordo::ACTIVO->value)->count()];

            foreach (Acordo::query()->where('estado', EstadoAcordo::ACTIVO->value)->get() as $acordo) {
                $p = $this->progresso->de($acordo);
                $p['cumprido'] ? $r['cumpridos']++ : $r['activos']++;
                $r['em_atraso'] += $p['em_atraso'] ? 1 : 0;
                $r['pago'] += $p['pago'];
                $r['em_falta'] += $p['em_falta'];
            }

            return $r;
        });
    }

    /**
     * Próximas prestações de acordos activos que vencem nos próximos `$dias` dias (hoje incluído). Unidades menores.
     *
     * @return array{quantidade: int, valor: int}
     */
    public function aVencer(string $hoje, int $dias = 7): array
    {
        return $this->totais->lembrar('acordos-a-vencer', ['dias' => $dias], function () use ($hoje, $dias) {
            $limite = date('Y-m-d', strtotime("{$hoje} +{$dias} days"));
            $quantidade = 0;
            $emFalta = 0;

            foreach (Acordo::query()->where('estado', EstadoAcordo::ACTIVO->value)->get() as $acordo) {
                $proxima = $this->progresso->de($acordo)['proxima'];

                if ($proxima !== null && $proxima['data_vencimento'] >= $hoje && $proxima['data_vencimento'] <= $limite) {
                    $quantidade++;
                    $emFalta += $proxima['em_falta'];
                }
            }

            return ['quantidade' => $quantidade, 'valor' => $emFalta];
        });
    }

    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';

        return Acordo::query()
            ->with(['aluno.dadosPessoa', 'matricula:id,numero_registo_matricula'])
            ->when(is_numeric($filtros['estado'] ?? null), fn ($q) => $q->where('estado', (int) $filtros['estado']))
            ->when($pesquisa !== '', fn ($q) => $q->where(fn ($g) => $g
                ->whereHas('matricula', fn ($m) => $m->whereContem('numero_registo_matricula', $pesquisa))
                ->orWhereHas('aluno', fn ($a) => $a->where(fn ($x) => $x
                    ->whereContem('numero_matricula', $pesquisa)
                    ->orWhereHas('dadosPessoa', fn ($p) => $p->whereContem('nome_completo', $pesquisa))))))
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (Acordo $a) => $this->linha($a));
    }

    /** @return array<string, mixed> */
    public function detalhe(Acordo $acordo): array
    {
        $acordo->load(['aluno.dadosPessoa', 'matricula.turma', 'propinas.propina']);

        return $this->linha($acordo) + [
            'turma_nome' => $acordo->matricula?->turma?->nome,
            'motivo' => $acordo->motivo,
            'motivo_encerramento' => $acordo->motivo_encerramento,
            'divida_inicial' => $acordo->divida_inicial->unidadesMenores(),
            'desconto' => $acordo->desconto->unidadesMenores(),
            'desconto_multa_pct' => $acordo->desconto_multa_pct,
            'prestacoes' => $this->progresso->de($acordo)['prestacoes'],
            'propinas' => $acordo->propinas->map(fn (AcordoPropina $ap) => [
                'propina_id' => $ap->propina_id,
                'periodo' => RotuloDePeriodo::periodo($ap->propina->periodo_inicio->toDateString(), $ap->propina->periodo_fim->toDateString()),
                'capital_inicial' => $ap->capital_inicial,
                'multa_inicial' => $ap->multa_inicial,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function linha(Acordo $a): array
    {
        $p = $this->progresso->de($a);

        return [
            'id' => $a->id,
            'matricula_id' => $a->matricula_id,
            'aluno_id' => $a->aluno_id,
            'aluno_nome' => $a->aluno?->dadosPessoa?->nome_completo,
            'aluno_numero' => $a->aluno?->numero_matricula,
            'matricula_numero' => $a->matricula?->numero_registo_matricula,
            'estado' => $a->estado->value,
            'estado_efectivo' => $p['estado_efectivo'],
            'total' => $p['total'],
            'pago' => $p['pago'],
            'em_falta' => $p['em_falta'],
            'proxima' => $p['proxima'],
            'em_atraso' => $p['em_atraso'],
            'criado_em' => $a->created_at?->toDateString(),
        ];
    }

    /**
     * Propinas da matrícula com algo por pagar (capital ou multa), a oferecer para o acordo.
     *
     * @return list<array<string, mixed>>
     */
    public function propinasEmDivida(Matricula $matricula): array
    {
        $noutroAcordo = AcordoPropina::idsEmAcordoActivo()->pluck('propina_id')->all();

        return Propina::query()->with('multa')->activas()->where('matricula_id', $matricula->id)
            ->orderBy('periodo_inicio')->get()
            ->filter(fn (Propina $p) => ($p->saldo()->unidadesMenores() > 0 || $p->multaPendente() > 0) && ! in_array($p->id, $noutroAcordo, true))
            ->map(fn (Propina $p) => [
                'id' => $p->id,
                'periodo' => RotuloDePeriodo::periodo($p->periodo_inicio->toDateString(), $p->periodo_fim->toDateString()),
                'capital' => $p->saldo()->unidadesMenores(),
                'multa' => $p->multaPendente(),
            ])->values()->all();
    }
}
