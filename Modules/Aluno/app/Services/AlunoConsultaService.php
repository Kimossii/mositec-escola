<?php

namespace Modules\Aluno\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Storage;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Aluno\Models\Aluno;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use InvalidArgumentException;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AlunoConsultaService
{
    /**
     * Conta de utilizador (login) deste aluno, se existir: a mesma ligação que o cadastro usa
     * (matrícula oficial ou pessoa). Só o que a ficha mostra — nunca senha nem ids de outros registos.
     *
     * @return array{id: int, login: ?string, estado: int, estado_descricao: ?string, criada_em: string}|null
     */
    public function contaDeUtilizador(Aluno $aluno): ?array
    {
        $conta = User::where('numero_matricula', $aluno->numero_matricula)
            ->orWhere('dados_pessoa_id', $aluno->dados_pessoa_id)
            ->first();

        return $conta === null ? null : [
            'id' => $conta->id,
            'login' => $conta->numero_matricula,
            'estado' => $conta->estado,
            'estado_descricao' => $conta->estado_descricao,
            'criada_em' => $conta->created_at->format('d/m/Y'),
        ];
    }

    /**
     * Serve a foto do aluno a partir do model (já filtrado por tenant), nunca de um
     * caminho recebido do cliente. 404 se não houver foto registada ou ficheiro.
     */
    public function servirFoto(Aluno $aluno): StreamedResponse
    {
        $disco = Storage::disk('privado');

        // Um caminho guardado que não esteja sob o prefixo do tenant trata-se como foto inexistente.
        try {
            $caminho = $aluno->foto_path ? CaminhoTenant::garantir($aluno->foto_path) : null;
        } catch (InvalidArgumentException) {
            $caminho = null;
        }

        abort_if($caminho === null || ! $disco->exists($caminho), 404);

        return $disco->response($caminho, null, [
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Valor especial do filtro de ano lectivo: alunos sem nenhuma matrícula. */
    public const SEM_MATRICULA = 'sem_matricula';

    /**
     * Números para os cartões da lista (ano lectivo escolhido): total, com matrícula activa, sem matrícula e inactivos.
     *
     * @return array<string, int>
     */
    public function indicadores(array $filtros = []): array
    {
        $ano = is_numeric($filtros['ano_lectivo_id'] ?? null) ? (int) $filtros['ano_lectivo_id'] : null;
        $doAno = fn ($q) => $q->when($ano !== null, fn ($m) => $m->where('ano_lectivo_id', $ano));

        return [
            'total' => Aluno::query()->count(),
            'com_matricula_activa' => Aluno::query()->whereHas('matriculas', fn ($q) => $doAno($q)->where('estado', EstadoMatriculaEnum::ACTIVA->value))->count(),
            'sem_matricula' => Aluno::query()->whereDoesntHave('matriculas', fn ($q) => $doAno($q)->where('estado', '!=', EstadoMatriculaEnum::CANCELADA->value))->count(),
            'inactivos' => Aluno::query()->where('estado', '!=', 1)->count(),
        ];
    }

    public function listar(array $filtros = [], int $porPagina = 15): LengthAwarePaginator
    {
        $semMatricula = ($filtros['ano_lectivo_id'] ?? null) === self::SEM_MATRICULA;
        if ($semMatricula) {
            // Sem matrícula não há ano/turma/curso/nível a filtrar.
            $filtros = array_diff_key($filtros, array_flip(['ano_lectivo_id', 'turma_id', 'curso_id', 'nivel_academico_id']));
        }

        return Aluno::with('dadosPessoa')
            ->where('estabelecimento_id', Estabelecimento::current()?->id)
            ->when($filtros['pesquisa'] ?? null, function ($query, $pesquisa) {
                $query->where(function ($query) use ($pesquisa) {
                    $query->whereContem('numero_matricula', $pesquisa)
                        ->orWhereHas('dadosPessoa', function ($query) use ($pesquisa) {
                            $query->whereContem('nome_completo', $pesquisa)
                                ->orWhereContem('numero_identificacao', $pesquisa);
                        });
                });
            })
            ->when($semMatricula, fn ($query) => $query->whereDoesntHave('matriculas'))
            ->when(
                ($filtros['ano_lectivo_id'] ?? null) || ($filtros['turma_id'] ?? null) || ($filtros['curso_id'] ?? null) || ($filtros['nivel_academico_id'] ?? null),
                function ($query) use ($filtros) {
                    $query->whereHas('matriculas', function ($query) use ($filtros) {
                        $query->when($filtros['ano_lectivo_id'] ?? null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
                            ->when($filtros['turma_id'] ?? null, fn ($query, $turmaId) => $query->where('turma_id', $turmaId))
                            ->when($filtros['curso_id'] ?? null, function ($query, $cursoId) {
                                $query->whereHas('turma', fn ($query) => $query->where('curso_id', $cursoId));
                            })
                            ->when($filtros['nivel_academico_id'] ?? null, function ($query, $nivelAcademicoId) {
                                $query->whereHas('turma', fn ($query) => $query->where('nivel_academico_id', $nivelAcademicoId));
                            });
                    });
                },
            )
            ->orderByDesc('numero_matricula')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function anosLectivosDisponiveis(): SupportCollection
    {
        return AnoLectivo::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderByDesc('data_inicio')
            ->get(['id', 'nome']);
    }

    /**
     * Opções do filtro de turma. Com um ano lectivo seleccionado no filtro
     * só traz as turmas desse ano — a lista não cresce com os anos, já que
     * uma turma de outro ano nunca daria resultados ao combinar-se com ele.
     * Sem ano ("todos os anos lectivos") traz todas.
     *
     * @param  array{ano_lectivo_id?: int|string|null}  $filtros
     */
    public function turmasDisponiveis(array $filtros = []): SupportCollection
    {
        $anoLectivoId = $filtros['ano_lectivo_id'] ?? null;

        return Turma::with(['anoLectivo', 'curso', 'nivelAcademico'])
            ->when($anoLectivoId !== self::SEM_MATRICULA ? $anoLectivoId : null, fn ($query, $anoLectivoId) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->whereHas('anoLectivo', fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
            ->orderByDesc('ano_lectivo_id')
            ->orderBy('codigo')
            ->get();
    }

    public function cursosDisponiveis(): SupportCollection
    {
        return Curso::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->where('estado', Estado::ATIVO->value)
            ->orderBy('nome')
            ->get(['id', 'nome']);
    }

    public function niveisAcademicosDisponiveis(): SupportCollection
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->where('estado', Estado::ATIVO->value)
            ->orderBy('ordem')
            ->get(['id', 'nome']);
    }
}
