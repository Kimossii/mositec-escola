<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Documentos\ExportadorDePautas;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Junta o documento certo (pauta, mini-pauta, competências, boletins) ao escritor certo (PDF ou Excel) e devolve a descarga. */
class ExportacaoDePautasService
{
    public function __construct(
        private PautaDocumentoService $pautas,
        private MiniPautaDocumentoService $miniPautas,
        private BoletimDocumentoService $boletins,
        private AcessoAMiniPautaService $acesso,
        private ExportadorDePautas $exportador,
    ) {
    }

    public function pauta(int $turmaId, string $formato): BinaryFileResponse
    {
        $turma = Turma::query()->findOrFail($turmaId);

        return $this->exportador->responder($this->pautas->paraTurma($turma->id), $formato, 'pauta-final-'.$turma->nome);
    }

    /** @param  string|null  $detalhe  `competencias` para o detalhe por competência (só Creche e Pré-Escolar) */
    public function miniPauta(User $utilizador, int $turmaId, int $disciplinaId, string $formato, ?string $detalhe = null, ?int $periodoId = null): BinaryFileResponse
    {
        $this->acesso->exigir($utilizador, $turmaId, $disciplinaId);

        if ($detalhe === 'competencias') {
            return $this->exportador->responder($this->miniPautas->competenciasParaTurmaDisciplina($turmaId, $disciplinaId, $periodoId), $formato, 'competencias');
        }

        return $this->exportador->responder($this->miniPautas->paraTurmaDisciplina($turmaId, $disciplinaId), $formato, 'mini-pauta');
    }

    /** Os boletins da turma, ou só o de uma matrícula; sempre em PDF, um por página. */
    public function boletins(int $turmaId, ?int $matriculaId = null): BinaryFileResponse
    {
        $turma = Turma::query()->findOrFail($turmaId);
        $matricula = $matriculaId === null ? null : Matricula::query()->where('turma_id', $turma->id)->findOrFail($matriculaId);

        return $this->exportador->responderBoletins($this->boletins->paraTurma($turma->id, $matricula?->id), ($matricula === null ? 'boletins-' : 'boletim-').$turma->nome);
    }
}
