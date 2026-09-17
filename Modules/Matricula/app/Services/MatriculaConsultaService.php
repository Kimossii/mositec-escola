<?php

namespace Modules\Matricula\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\InscreverDisciplinasAutomaticamenteAction;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

class MatriculaConsultaService
{
    public function __construct(
        private InscreverDisciplinasAutomaticamenteAction $inscreverDisciplinas,
    ) {
    }

    public function listarPorAluno(Aluno $aluno, array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return Matricula::with(['turma.curso', 'turma.nivelAcademico', 'anoLectivo'])
            ->where('aluno_id', $aluno->id)
            ->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->where('numero_registo_matricula', 'like', "%{$pesquisa}%")
                        ->orWhereHas('turma', function ($query) use ($pesquisa) {
                            $query->where('codigo', 'like', "%{$pesquisa}%")
                                ->orWhere('nome', 'like', "%{$pesquisa}%");
                        });
                });
            })
            ->orderByDesc('data_matricula')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->appends($filtros);
    }

    /**
     * Matrículas não terminais (Pendente/Activa) do aluno no ano lectivo
     * activo do seu estabelecimento, da mais para a menos recente. No
     * Ensino Superior um aluno pode ter mais de uma em simultâneo (ex.:
     * cursos diferentes) — matriculaActual() devolve só a primeira, o
     * resto fica disponível para quem precisar de as listar todas.
     */
    public function matriculasActivasNoAnoLectivo(Aluno $aluno): Collection
    {
        $anoLectivoAtivoId = AnoLectivo::current($aluno->estabelecimento_id)?->id;

        if ($anoLectivoAtivoId === null) {
            return new Collection();
        }

        return Matricula::with(['turma.curso', 'turma.nivelAcademico', 'turma.turno', 'turma.turmaSalas.sala', 'anoLectivo'])
            ->where('aluno_id', $aluno->id)
            ->where('ano_lectivo_id', $anoLectivoAtivoId)
            ->whereIn('estado', [EstadoMatriculaEnum::PENDENTE->value, EstadoMatriculaEnum::ACTIVA->value])
            ->orderByDesc('data_matricula')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * A matrícula não terminal mais recente do aluno no ano lectivo activo
     * — usada para mostrar Curso/Turma/Nível Académico/Ano na ficha do
     * aluno. Devolve null quando o aluno não está matriculado (ou só tem
     * matrículas terminais) no ano lectivo actualmente activo.
     */
    public function matriculaActual(Aluno $aluno): ?Matricula
    {
        return $this->matriculasActivasNoAnoLectivo($aluno)->first();
    }

    /**
     * Anos lectivos distintos em que o aluno já teve matrícula — usado para
     * as opções do filtro por ano lectivo na página do aluno (não pode vir
     * da página paginada, que só tem uma fatia dos registos).
     */
    public function anosLectivosComMatricula(Aluno $aluno): SupportCollection
    {
        return Matricula::where('aluno_id', $aluno->id)
            ->select('ano_lectivo_id')
            ->distinct()
            ->with('anoLectivo:id,nome,data_inicio')
            ->get()
            ->pluck('anoLectivo')
            ->filter()
            ->sortByDesc('data_inicio')
            ->values();
    }

    public function historicoDaMatricula(Matricula $matricula): Collection
    {
        return $matricula->historico()
            ->with('utilizador')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function listarDisciplinasDaMatricula(Matricula $matricula): Collection
    {
        return InscricaoDisciplina::with('planoCurricularDisciplina.disciplina')
            ->where('matricula_id', $matricula->id)
            ->orderBy('data_inscricao')
            ->get();
    }

    /**
     * Disciplinas do Plano Curricular aplicável a ESTA matrícula (via
     * InscreverDisciplinasAutomaticamenteAction::resolverPlanoCurricular —
     * mesma resolução usada na inscrição automática), excluindo as que o
     * aluno já tem Inscrita numa matrícula aberta (mesma regra da Fix 2 em
     * CriarInscricaoDisciplinaAction). Serve só para alimentar o seletor da
     * UI — o backend continua a validar tudo no momento de inscrever.
     */
    public function disciplinasDisponiveisParaInscricao(Matricula $matricula): SupportCollection
    {
        $plano = $this->inscreverDisciplinas->resolverPlanoCurricular($matricula->turma);

        if ($plano === null) {
            return collect();
        }

        $plano->loadMissing('disciplinas.disciplina');

        $disciplinasJaInscritas = InscricaoDisciplina::query()
            ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA->value)
            ->whereHas('matricula', function ($query) use ($matricula) {
                $query->where('aluno_id', $matricula->aluno_id)
                    ->whereIn('estado', [EstadoMatriculaEnum::PENDENTE->value, EstadoMatriculaEnum::ACTIVA->value]);
            })
            ->with('planoCurricularDisciplina:id,disciplina_id')
            ->get()
            ->pluck('planoCurricularDisciplina.disciplina_id');

        return $plano->disciplinas
            ->reject(fn ($planoCurricularDisciplina) => $disciplinasJaInscritas->contains($planoCurricularDisciplina->disciplina_id))
            ->values();
    }

    public function turmasDisponiveis(): Collection
    {
        return Turma::with(['anoLectivo', 'curso', 'nivelAcademico', 'turno'])
            ->where('estado', Estado::ATIVO->value)
            ->whereHas('anoLectivo', fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('codigo')
            ->get();
    }

    public function listarTodas(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return Matricula::query()
            ->with(['aluno.dadosPessoa', 'turma.curso', 'turma.nivelAcademico', 'anoLectivo'])
            ->whereHas('aluno', fn ($query) => $query->where('estabelecimento_id', $estabelecimentoId))
            ->when($filtros['turma_id'] ?? null, fn ($query, $turmaId) => $query->where('turma_id', $turmaId))
            ->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->whereHas('aluno', function ($query) use ($pesquisa) {
                    $query->where('numero_matricula', 'like', "%{$pesquisa}%")
                        ->orWhereHas('dadosPessoa', function ($query) use ($pesquisa) {
                            $query->where('nome_completo', 'like', "%{$pesquisa}%")
                                ->orWhere('numero_identificacao', 'like', "%{$pesquisa}%");
                        });
                });
            })
            ->orderByDesc('data_matricula')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();
    }
}
