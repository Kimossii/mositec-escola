<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\CalendarioDePlano;
use Modules\Financeiro\Support\Precedencia;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

class PlanoPropinaConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return PlanoPropina::query()
            ->with(['anoLectivo', 'alvos.nivelAcademico', 'alvos.curso', 'alvos.turno', 'alvos.turma'])
            ->when(in_array($filtros['estado'] ?? null, ['0', '1', 0, 1], true), fn ($query) => $query->where('estado', $filtros['estado']))
            ->when(is_numeric($filtros['ano_lectivo_id'] ?? null), fn ($query) => $query->where('ano_lectivo_id', (int) $filtros['ano_lectivo_id']))
            ->when(is_string($filtros['pesquisa'] ?? null) && $filtros['pesquisa'] !== '', fn ($query) => $query->whereContem('nome', $filtros['pesquisa']))
            ->orderBy('ano_lectivo_id', 'desc')
            ->orderBy('nome')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (PlanoPropina $plano) => [
                'id' => $plano->id,
                'nome' => $plano->nome,
                'descricao' => $plano->descricao,
                'ano_lectivo_id' => $plano->ano_lectivo_id,
                'ano_lectivo_nome' => $plano->anoLectivo?->nome,
                'periodicidade' => $plano->periodicidade->value,
                'periodicidade_descricao' => $plano->periodicidade_descricao,
                'intervalo_meses' => $plano->intervalo_meses,
                'valor' => $plano->valor->unidadesMenores(),
                'mes_inicio' => $plano->mes_inicio,
                'mes_fim' => $plano->mes_fim,
                'periodos_total' => count($plano->periodos()),
                'precedencia' => $this->precedenciaDoPlano($plano),
                'alvos' => $plano->alvos->map(fn (PlanoPropinaAlvo $alvo) => [
                    'nivel_academico_id' => $alvo->nivel_academico_id,
                    'nivel_nome' => $alvo->nivelAcademico?->nome,
                    'curso_id' => $alvo->curso_id,
                    'curso_nome' => $alvo->curso?->nome,
                    'turno_id' => $alvo->turno_id,
                    'turno_nome' => $alvo->turno?->nome,
                    'turma_id' => $alvo->turma_id,
                    'turma_nome' => $alvo->turma?->nome,
                    'precedencia' => Precedencia::descricao($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])),
                ])->values()->all(),
                'estado' => $plano->estado,
                'estado_descricao' => $plano->estado_descricao,
            ]);
    }

    /**
     * Há outro plano (não $ignorarPlanoId) do mesmo ano lectivo, com competências sobrepostas
     * às dadas, e com algum dos mesmos alvos? Planos sem alvos contam como o alvo vazio
     * (dois "gerais" colidem). Planos inactivos contam.
     *
     * @param  array<int, mixed>  $alvos
     * @param  list<array{ano: int, mes: int}>  $competencias
     */
    public function colisaoDeAlvos(int $anoLectivoId, array $alvos, array $competencias, ?int $ignorarPlanoId): bool
    {
        $chaves = array_map(fn (array $alvo) => AlvosDoPlano::chave($alvo), AlvosDoPlano::normalizar($alvos));

        $outros = PlanoPropina::query()
            ->where('ano_lectivo_id', $anoLectivoId)
            ->when($ignorarPlanoId !== null, fn ($query) => $query->where('id', '!=', $ignorarPlanoId))
            ->with(['alvos', 'anoLectivo'])
            ->get();

        foreach ($outros as $outro) {
            if (! CalendarioDePlano::sobrepoem($competencias, $outro->competencias())) {
                continue;
            }

            $alvosDoOutro = AlvosDoPlano::normalizar($outro->alvos->map(fn (PlanoPropinaAlvo $alvo) => $alvo->only([
                'nivel_academico_id', 'curso_id', 'turno_id', 'turma_id',
            ]))->all());

            foreach ($alvosDoOutro as $alvoDoOutro) {
                if (in_array(AlvosDoPlano::chave($alvoDoOutro), $chaves, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Alguma das turmas dadas não pertence ao ano lectivo?
     *
     * @param  list<int>  $turmaIds
     */
    public function turmasForaDoAno(int $anoLectivoId, array $turmaIds): bool
    {
        if ($turmaIds === []) {
            return false;
        }

        $doAno = Turma::query()->whereIn('id', $turmaIds)->where('ano_lectivo_id', $anoLectivoId)->count();

        return $doAno !== count(array_unique($turmaIds));
    }

    /** Ano lectivo activo, usado como filtro por omissão da lista; null se não houver. */
    public function anoLectivoActualId(): ?int
    {
        return AnoLectivo::current()?->id;
    }

    /**
     * @return Collection<int, AnoLectivo>
     */
    public function anosLectivos(): Collection
    {
        return AnoLectivo::query()->orderByDesc('data_inicio')->get(['id', 'nome']);
    }

    /**
     * @return Collection<int, NivelAcademico>
     */
    public function niveis(): Collection
    {
        return NivelAcademico::query()->orderBy('ordem')->orderBy('nome')->get(['id', 'nome']);
    }

    /**
     * @return Collection<int, Curso>
     */
    public function cursos(): Collection
    {
        return Curso::query()->orderBy('nome')->get(['id', 'nome']);
    }

    /**
     * @return Collection<int, Turno>
     */
    public function turnos(): Collection
    {
        return Turno::query()->orderBy('nome')->get(['id', 'nome']);
    }

    /**
     * @return Collection<int, Turma>
     */
    public function turmas(): Collection
    {
        return Turma::query()->orderBy('nome')->get(['id', 'nome', 'ano_lectivo_id']);
    }

    private function precedenciaDoPlano(PlanoPropina $plano): string
    {
        if ($plano->alvos->isEmpty()) {
            return Precedencia::descricao([]);
        }

        return $plano->alvos
            ->map(fn (PlanoPropinaAlvo $alvo) => Precedencia::descricao($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])))
            ->unique()
            ->implode('; ');
    }
}
