<?php

namespace Modules\Docente\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as ColecaoSimples;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;

/** Leitura das atribuições docentes: vista por turma, lista filtrável, indicadores e opções dos selectores. */
class AtribuicaoDocenteConsultaService
{
    public const MOTIVO_TURMA_SEM_PLANO = 'Esta turma ainda não tem disciplinas definidas: não existe um plano curricular activo para o seu curso, nível e ano lectivo. Defina o plano curricular antes de atribuir docentes.';

    public const MOTIVO_TURMA_NAO_ENCONTRADA = 'A turma indicada não existe neste ano lectivo.';

    /** Estado de vigência por extenso, como se lê nos ficheiros exportados. */
    private const ESTADOS = ['em_curso' => 'Em curso', 'agendada' => 'Agendada', 'terminada' => 'Terminada'];

    public function __construct(
        private DisciplinasAtribuiveisService $disciplinas,
        private RelogioDoTenant $relogio,
    ) {
    }

    /**
     * O ano lectivo activo do tenant; sem activo (transição entre anos), o mais recente. Nulo se não houver nenhum.
     * Nunca «todos os anos»: misturaria anos e mostraria estados com uma referência de data errada.
     */
    public function anoLectivoPadrao(): ?int
    {
        return AnoLectivo::current()?->id
            ?? AnoLectivo::query()->orderByDesc('data_inicio')->orderByDesc('id')->value('id');
    }

    /**
     * Uma linha por disciplina atribuível da turma. `titular` é a atribuição de titular em vigor hoje;
     * `substitutos` são os substitutos em vigor ou agendados (campo `estado`). `conta_como_em_falta` = sem titular
     * e (disciplina obrigatória no plano ou com inscrição activa na turma).
     *
     * @return array{linhas: list<array<string, mixed>>, motivo_vazio: ?string}
     */
    public function porTurma(int $anoLectivoId, int $turmaId): array
    {
        $turma = Turma::query()->where('ano_lectivo_id', $anoLectivoId)->find($turmaId);

        if ($turma === null) {
            return ['linhas' => [], 'motivo_vazio' => self::MOTIVO_TURMA_NAO_ENCONTRADA];
        }

        $disciplinas = $this->disciplinas->daTurma($turma);

        if ($disciplinas->isEmpty()) {
            return ['linhas' => [], 'motivo_vazio' => self::MOTIVO_TURMA_SEM_PLANO];
        }

        $hoje = $this->dataDeReferencia($anoLectivoId);
        $obrigatorias = $this->obrigatoriasDoPlano($turma);
        $comInscricoes = $this->disciplinasComInscricaoActiva($turma);

        $atribuicoes = AtribuicaoDocente::query()
            ->with('docente.dadosPessoa:id,nome_completo')
            ->where('turma_id', $turma->id)
            ->whereIn('disciplina_id', $disciplinas->pluck('id'))
            ->where(fn ($q) => $q->whereNull('data_fim')->orWhereDate('data_fim', '>=', $hoje->toDateString()))
            ->orderBy('data_inicio')
            ->get()
            ->groupBy('disciplina_id');

        $linhas = $disciplinas->map(function ($disciplina) use ($atribuicoes, $obrigatorias, $comInscricoes, $hoje) {
            $daDisciplina = ($atribuicoes[$disciplina->id] ?? new ColecaoSimples())
                ->map(fn (AtribuicaoDocente $a) => $this->linhaDeAtribuicao($a, $a->estadoNaData($hoje)));

            $titular = $daDisciplina->first(fn ($a) => $a['papel'] === PapelDocente::TITULAR->value && $a['estado'] === 'em_curso');
            $titularAgendado = $daDisciplina->first(fn ($a) => $a['papel'] === PapelDocente::TITULAR->value && $a['estado'] === 'agendada');
            $semTitular = $titular === null;

            return [
                'disciplina_id' => $disciplina->id,
                'disciplina_nome' => $disciplina->nome,
                'obrigatoria' => $obrigatorias[$disciplina->id] ?? true,
                'titular' => $titular,
                'titular_agendado' => $titularAgendado,
                'substitutos' => $daDisciplina->filter(fn ($a) => $a['papel'] === PapelDocente::SUBSTITUTO->value)->values()->all(),
                'sem_titular' => $semTitular,
                'conta_como_em_falta' => $semTitular && (($obrigatorias[$disciplina->id] ?? true) || in_array($disciplina->id, $comInscricoes, true)),
            ];
        })->values()->all();

        return ['linhas' => $linhas, 'motivo_vazio' => null];
    }

    /**
     * @param  array{ano_lectivo_id?: mixed, turma_id?: mixed, docente_id?: mixed, disciplina_id?: mixed, papel?: mixed, estado_vigencia?: mixed, pesquisa?: mixed}  $filtros
     */
    public function lista(array $filtros, int $porPagina = 15): LengthAwarePaginator
    {
        $hoje = $this->referenciaDosFiltros($filtros);

        return $this->consultaFiltrada($filtros, $hoje)
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (AtribuicaoDocente $a) => $this->linhaDeLista($a, $a->estadoNaData($hoje)));
    }

    /**
     * Linhas da lista, com os mesmos filtros e a mesma referência de data, prontas a exportar (sem paginação).
     *
     * @param  array{ano_lectivo_id?: mixed, turma_id?: mixed, docente_id?: mixed, disciplina_id?: mixed, papel?: mixed, estado_vigencia?: mixed, pesquisa?: mixed}  $filtros
     * @return list<array<string, string>>
     */
    public function paraExportacao(array $filtros, int $limite): array
    {
        $hoje = $this->referenciaDosFiltros($filtros);

        return $this->consultaFiltrada($filtros, $hoje)
            ->limit($limite)
            ->get()
            ->map(fn (AtribuicaoDocente $a) => [
                'docente' => (string) $a->docente?->dadosPessoa?->nome_completo,
                'turma' => (string) $a->turma?->rotulo(completo: true),
                'disciplina' => (string) $a->disciplina?->nome,
                'papel' => $a->papel->label(),
                'inicio' => $a->data_inicio->toDateString(),
                'fim' => $a->data_fim?->toDateString() ?? '',
                'estado' => self::ESTADOS[$a->estadoNaData($hoje)] ?? $a->estadoNaData($hoje),
            ])->all();
    }

    /** @param  array<string, mixed>  $filtros */
    private function referenciaDosFiltros(array $filtros): CarbonInterface
    {
        return $this->dataDeReferencia(isset($filtros['ano_lectivo_id']) ? (int) $filtros['ano_lectivo_id'] : null);
    }

    /** @param  array<string, mixed>  $filtros */
    private function consultaFiltrada(array $filtros, CarbonInterface $hoje): Builder
    {
        $dia = $hoje->toDateString();

        return AtribuicaoDocente::query()
            ->with(['turma:id,nome,codigo,turno_id,ano_lectivo_id', 'turma.turno:id,nome', 'turma.anoLectivo:id,nome', 'disciplina:id,nome', 'docente.dadosPessoa:id,nome_completo'])
            ->when($filtros['ano_lectivo_id'] ?? null, fn ($q, $v) => $q->where('ano_lectivo_id', (int) $v))
            ->when($filtros['turma_id'] ?? null, fn ($q, $v) => $q->where('turma_id', (int) $v))
            ->when($filtros['docente_id'] ?? null, fn ($q, $v) => $q->where('docente_id', (int) $v))
            ->when($filtros['disciplina_id'] ?? null, fn ($q, $v) => $q->where('disciplina_id', (int) $v))
            ->when(($filtros['papel'] ?? '') !== '' && isset($filtros['papel']), fn ($q) => $q->where('papel', (int) $filtros['papel']))
            ->when($filtros['estado_vigencia'] ?? null, fn ($q, $estado) => match ($estado) {
                'agendada' => $q->whereDate('data_inicio', '>', $dia),
                'terminada' => $q->whereNotNull('data_fim')->whereDate('data_fim', '<', $dia),
                'em_curso' => $q->emVigor($hoje),
                default => $q,
            })
            ->when($filtros['pesquisa'] ?? null, fn ($q, $pesquisa) => $q->where(fn ($q) => $q
                ->whereHas('docente.dadosPessoa', fn ($p) => $p->whereContem('nome_completo', $pesquisa))
                ->orWhereHas('disciplina', fn ($d) => $d->whereContem('nome', $pesquisa))
                ->orWhereHas('turma', fn ($t) => $t->whereContem('nome', $pesquisa)->orWhereContem('codigo', $pesquisa))))
            ->orderBy('turma_id')
            ->orderBy('disciplina_id')
            ->orderBy('data_inicio')
            ->orderBy('id');
    }

    /**
     * `em_vigor`: atribuições em vigor hoje nas turmas do ano. `disciplinas_sem_titular`: disciplinas que contam
     * como em falta, somadas nas turmas activas do ano.
     *
     * @return array{em_vigor: int, disciplinas_sem_titular: int}
     */
    public function indicadores(int $anoLectivoId): array
    {
        $hoje = $this->dataDeReferencia($anoLectivoId);
        $turmas = Turma::query()->where('ano_lectivo_id', $anoLectivoId)->where('estado', 1)->get();

        $titularesEmVigor = AtribuicaoDocente::query()
            ->whereIn('turma_id', $turmas->pluck('id'))
            ->where('papel', PapelDocente::TITULAR->value)
            ->emVigor($hoje)
            ->get(['turma_id', 'disciplina_id'])
            ->map(fn ($a) => $a->turma_id . ':' . $a->disciplina_id)
            ->flip();

        // O plano resolve-se uma vez por (curso, nível); as inscrições, numa só consulta para o ano.
        $planos = [];
        $inscricoes = $this->inscricoesActivasDoAno($turmas);
        $semTitular = 0;

        foreach ($turmas as $turma) {
            $chave = $turma->curso_id . ':' . $turma->nivel_academico_id;
            $planos[$chave] ??= $this->disciplinas->linhasDoPlano($turma);

            foreach ($planos[$chave] as $linha) {
                if ($titularesEmVigor->has($turma->id . ':' . $linha['disciplina_id'])) {
                    continue;
                }

                if ($linha['obrigatoria'] || isset($inscricoes[$turma->id . ':' . $linha['disciplina_id']])) {
                    $semTitular++;
                }
            }
        }

        return [
            'em_vigor' => AtribuicaoDocente::query()->where('ano_lectivo_id', $anoLectivoId)->emVigor($hoje)->count(),
            'disciplinas_sem_titular' => $semTitular,
        ];
    }

    /**
     * `docentes`: os activos, com ou sem conta (`tem_acesso`: se já podem entrar no sistema, p. ex. para lançar notas).
     * `docentes_filtro`: os que têm atribuições no ano, activos ou não (o histórico de um docente inactivo continua pesquisável).
     * `disciplinas`: as que têm atribuições no ano (as únicas que o filtro da lista pode encontrar).
     *
     * @return array{anosLectivos: list<array<string, mixed>>, turmas: list<array<string, mixed>>, docentes: list<array{id: int, nome: string, tem_acesso: bool}>, disciplinas: list<array{id: int, nome: string}>, docentes_filtro: list<array{id: int, nome: string, activo: bool}>}
     */
    public function opcoes(int $anoLectivoId): array
    {
        return [
            'anosLectivos' => AnoLectivo::query()->orderByDesc('data_inicio')->get()
                ->map(fn (AnoLectivo $a) => ['id' => $a->id, 'nome' => $a->nome, 'estado' => $a->estado->value, 'activo' => $a->estado->value === 1])
                ->all(),
            'turmas' => Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome'])->where('ano_lectivo_id', $anoLectivoId)->where('estado', 1)->orderBy('nome')->get(['id', 'nome', 'codigo', 'turno_id', 'ano_lectivo_id'])
                ->map(fn (Turma $t) => ['id' => $t->id, 'nome' => $t->nome, 'codigo' => $t->codigo, 'rotulo' => $t->rotulo(completo: true)])
                ->all(),
            'docentes' => $this->docentesActivos(),
            'docentes_filtro' => $this->docentesDoFiltro($anoLectivoId),
            'disciplinas' => Disciplina::query()
                ->whereIn('id', AtribuicaoDocente::query()->where('ano_lectivo_id', $anoLectivoId)->select('disciplina_id'))
                ->orderBy('nome')->get(['id', 'nome'])
                ->map(fn (Disciplina $d) => ['id' => $d->id, 'nome' => $d->nome])
                ->all(),
        ];
    }

    /** @return list<array{id: int, nome: string, activo: bool}> */
    private function docentesDoFiltro(int $anoLectivoId): array
    {
        return Docente::query()
            ->whereIn('docentes.id', AtribuicaoDocente::query()->where('ano_lectivo_id', $anoLectivoId)->select('docente_id'))
            ->join('dados_pessoas', 'dados_pessoas.id', '=', 'docentes.dados_pessoa_id')
            ->orderBy('dados_pessoas.nome_completo')
            ->orderBy('docentes.id')
            ->get(['docentes.id', 'docentes.estado', 'dados_pessoas.nome_completo'])
            ->map(fn (Docente $d) => ['id' => $d->id, 'nome' => $d->nome_completo, 'activo' => (int) $d->estado === 1])
            ->values()->all();
    }

    /**
     * Docentes activos por nome. O acesso resolve-se numa só consulta às contas com a mesma pessoa (sem N+1).
     *
     * @return list<array{id: int, nome: string, tem_acesso: bool}>
     */
    private function docentesActivos(): array
    {
        $docentes = Docente::query()
            ->where('docentes.estado', 1)
            ->join('dados_pessoas', 'dados_pessoas.id', '=', 'docentes.dados_pessoa_id')
            ->orderBy('dados_pessoas.nome_completo')
            ->orderBy('docentes.id')
            ->get(['docentes.id', 'docentes.dados_pessoa_id', 'dados_pessoas.nome_completo']);

        $comConta = User::query()->whereIn('dados_pessoa_id', $docentes->pluck('dados_pessoa_id'))->pluck('dados_pessoa_id')->flip();

        return $docentes->map(fn (Docente $d) => [
            'id' => $d->id,
            'nome' => $d->nome_completo,
            'tem_acesso' => $comConta->has($d->dados_pessoa_id),
        ])->values()->all();
    }

    /**
     * Data de referência de toda a leitura: «hoje» (fuso do tenant) limitada a [início, fim] do ano consultado.
     * Ano encerrado mostra o estado no último dia, planeado no primeiro, activo mantém «hoje». Sem ano: «hoje».
     */
    private function dataDeReferencia(?int $anoLectivoId): CarbonInterface
    {
        $hoje = $this->relogio->hoje();
        $ano = $anoLectivoId ? AnoLectivo::query()->find($anoLectivoId) : null;

        if ($ano === null) {
            return $hoje;
        }

        if ($hoje->toDateString() < $ano->data_inicio->toDateString()) {
            return $ano->data_inicio->toImmutable();
        }

        if ($hoje->toDateString() > $ano->data_fim->toDateString()) {
            return $ano->data_fim->toImmutable();
        }

        return $hoje;
    }

    /** @return array<string, mixed> */
    private function linhaDeAtribuicao(AtribuicaoDocente $a, string $estado): array
    {
        return [
            'id' => $a->id,
            'docente_id' => $a->docente_id,
            'docente_nome' => $a->docente?->dadosPessoa?->nome_completo,
            'papel' => $a->papel->value,
            'papel_descricao' => $a->papel_descricao,
            'data_inicio' => $a->data_inicio->toDateString(),
            'data_fim' => $a->data_fim?->toDateString(),
            'estado' => $estado,
        ];
    }

    /** @return array<string, mixed> */
    private function linhaDeLista(AtribuicaoDocente $a, string $estado): array
    {
        return $this->linhaDeAtribuicao($a, $estado) + [
            'turma_id' => $a->turma_id,
            'turma_nome' => $a->turma?->nome,
            'turma_rotulo' => $a->turma?->rotulo(),
            'disciplina_id' => $a->disciplina_id,
            'disciplina_nome' => $a->disciplina?->nome,
            'motivo_encerramento' => $a->motivo_encerramento,
        ];
    }

    /** @return array<int, bool> obrigatoriedade por disciplina do plano da turma */
    private function obrigatoriasDoPlano(Turma $turma): array
    {
        $out = [];
        foreach ($this->disciplinas->linhasDoPlano($turma) as $linha) {
            $out[$linha['disciplina_id']] = $linha['obrigatoria'];
        }

        return $out;
    }

    /** @return list<int> */
    private function disciplinasComInscricaoActiva(Turma $turma): array
    {
        return collect($this->inscricoesActivasDoAno(new Collection([$turma])))
            ->keys()
            ->map(fn ($chave) => (int) explode(':', $chave)[1])
            ->all();
    }

    /**
     * Pares «turma:disciplina» com pelo menos uma inscrição activa (matrícula activa, inscrição «inscrita»).
     *
     * @return array<string, true>
     */
    private function inscricoesActivasDoAno(Collection $turmas): array
    {
        if ($turmas->isEmpty()) {
            return [];
        }

        return InscricaoDisciplina::query()
            ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA->value)
            ->whereHas('matricula', fn ($q) => $q->whereIn('turma_id', $turmas->pluck('id'))->where('estado', EstadoMatriculaEnum::ACTIVA->value))
            ->with(['matricula:id,turma_id', 'planoCurricularDisciplina:id,disciplina_id'])
            ->get()
            ->mapWithKeys(fn (InscricaoDisciplina $i) => [$i->matricula->turma_id . ':' . $i->planoCurricularDisciplina?->disciplina_id => true])
            ->all();
    }
}
