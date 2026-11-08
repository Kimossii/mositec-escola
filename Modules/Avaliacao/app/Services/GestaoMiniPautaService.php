<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Actions\AnularNotaAction;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\EliminarInstrumentoAction;
use Modules\Avaliacao\Actions\LancarCompetenciaAction;
use Modules\Avaliacao\Actions\LancarExameAction;
use Modules\Avaliacao\Actions\LancarNotasEmLoteAction;
use Modules\Avaliacao\Actions\LancarNotaAction;
use Modules\Avaliacao\Actions\LancarQualitativaAction;
use Modules\Avaliacao\Actions\LancarRecursoAction;
use Modules\Avaliacao\DTO\AnularNotaDTO;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarCompetenciaDTO;
use Modules\Avaliacao\DTO\LancarExameDTO;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\DTO\LancarNotasEmLoteDTO;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Http\Requests\AnularNotaRequest;
use Modules\Avaliacao\Http\Requests\CriarInstrumentoRequest;
use Modules\Avaliacao\Http\Requests\LancarCompetenciaRequest;
use Modules\Avaliacao\Http\Requests\LancarExameRequest;
use Modules\Avaliacao\Http\Requests\LancarNotaRequest;
use Modules\Avaliacao\Http\Requests\LancarNotasEmLoteRequest;
use Modules\Avaliacao\Http\Requests\LancarQualitativaRequest;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Usuario\Models\User;

/** Os pedidos de escrita da mini-pauta: valida o acesso, monta o DTO a partir do pedido e entrega à Action. */
class GestaoMiniPautaService
{
    public function __construct(
        private AcessoAMiniPautaService $acesso,
        private LancarNotaAction $lancarNota,
        private LancarExameAction $lancarExame,
        private LancarRecursoAction $lancarRecurso,
        private LancarQualitativaAction $lancarQualitativa,
        private LancarCompetenciaAction $lancarCompetencia,
        private AnularNotaAction $anularNota,
        private LancarNotasEmLoteAction $lancarEmLote,
        private CriarInstrumentoAction $criarInstrumento,
        private EliminarInstrumentoAction $eliminarInstrumento,
    ) {
    }

    public function lancar(LancarNotaRequest $request): void
    {
        $d = $request->validated();

        $this->lancarNota->executar(new LancarNotaDTO((int) $d['instrumento_id'], (int) $d['inscricao_disciplina_id'], $d['valor'], $d['motivo'] ?? null));
    }

    /** @return int quantas notas se gravaram */
    public function lancarEmLote(LancarNotasEmLoteRequest $request): int
    {
        $d = $request->validated();
        $turmaId = (int) $d['turma_id'];
        $disciplinaId = (int) $d['disciplina_id'];
        $this->acesso->exigir($request->user(), $turmaId, $disciplinaId);

        $exame = fn (array $n) => new LancarExameDTO($turmaId, $disciplinaId, (int) $n['inscricao_disciplina_id'], $n['valor'], $n['motivo'] ?? null);

        return $this->lancarEmLote->executar(new LancarNotasEmLoteDTO(
            notas: array_map(fn (array $n) => new LancarNotaDTO((int) $n['instrumento_id'], (int) $n['inscricao_disciplina_id'], $n['valor'], $n['motivo'] ?? null), $d['notas'] ?? []),
            exames: array_map($exame, $d['exames'] ?? []),
            recursos: array_map($exame, $d['recursos'] ?? []),
        ));
    }

    public function lancarExame(LancarExameRequest $request): void
    {
        $this->lancarExame->executar($this->exame($request));
    }

    public function lancarRecurso(LancarExameRequest $request): void
    {
        $this->lancarRecurso->executar($this->exame($request));
    }

    public function lancarQualitativa(LancarQualitativaRequest $request): void
    {
        $d = $request->validated();

        $this->lancarQualitativa->executar(new LancarQualitativaDTO(
            (int) $d['inscricao_disciplina_id'], (int) $d['periodo_id'], (int) $d['escala_nivel_id'], $d['observacao'] ?? null, $d['motivo'] ?? null,
        ));
    }

    public function lancarCompetencia(LancarCompetenciaRequest $request): void
    {
        $d = $request->validated();

        $this->lancarCompetencia->executar(new LancarCompetenciaDTO(
            (int) $d['inscricao_disciplina_id'], (int) $d['competencia_id'], (int) $d['periodo_id'], (int) $d['escala_nivel_id'], $d['motivo'] ?? null,
        ));
    }

    public function anular(int $notaId, AnularNotaRequest $request): void
    {
        $d = $request->validated();

        $this->anularNota->executar(new AnularNotaDTO($notaId, (bool) $d['conta_como_zero'], $d['motivo']));
    }

    public function criarInstrumento(CriarInstrumentoRequest $request): void
    {
        $d = $request->validated();
        $this->acesso->exigir($request->user(), (int) $d['turma_id'], (int) $d['disciplina_id']);

        $this->criarInstrumento->executar(new InstrumentoDTO(
            (int) $d['turma_id'], (int) $d['disciplina_id'], (int) $d['periodo_id'], TipoInstrumento::from((int) $d['tipo']), (string) ($d['nome'] ?? ''), (bool) ($d['obrigatorio'] ?? true),
        ));
    }

    public function eliminarInstrumento(User $utilizador, int $instrumentoId): void
    {
        $instrumento = AvaliacaoInstrumento::query()->findOrFail($instrumentoId);
        $this->acesso->exigir($utilizador, $instrumento->turma_id, $instrumento->disciplina_id);

        $this->eliminarInstrumento->executar($instrumento->id);
    }

    private function exame(LancarExameRequest $request): LancarExameDTO
    {
        $d = $request->validated();

        return new LancarExameDTO((int) $d['turma_id'], (int) $d['disciplina_id'], (int) $d['inscricao_disciplina_id'], $d['valor'], $d['motivo'] ?? null);
    }
}
