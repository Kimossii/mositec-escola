<?php

namespace Modules\Matricula\Services;

use Modules\Aluno\Models\Aluno;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\ReactivarMatriculaAction;
use Modules\Matricula\Actions\TrancarMatriculaAction;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\Actions\EliminarMatriculaAction;
use Modules\Matricula\Actions\RenovarMatriculaAction;
use Modules\Matricula\Actions\RenovarMatriculasEmMassaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Http\Requests\AtualizarMatriculaRequest;
use Modules\Matricula\Http\Requests\CriarMatriculaRequest;
use Modules\Matricula\Models\Matricula;

class GestaoMatriculaService
{
    public function __construct(
        private CriarMatriculaAction $criarMatricula,
        private AtualizarMatriculaAction $atualizarMatricula,
        private AlterarEstadoMatriculaAction $alterarEstadoMatricula,
        private TrancarMatriculaAction $trancarMatricula,
        private ReactivarMatriculaAction $reactivarMatricula,
        private RenovarMatriculaAction $renovarMatricula,
        private EliminarMatriculaAction $eliminarMatricula,
        private RenovarMatriculasEmMassaAction $renovarMatriculasEmMassa,
    ) {
    }

    public function criar(Aluno $aluno, CriarMatriculaRequest $request): Matricula
    {
        return $this->criarMatricula->executar(
            $aluno,
            MatriculaDTO::fromCriarRequest($request),
            auth()->id(),
        );
    }

    public function atualizar(Matricula $matricula, AtualizarMatriculaRequest $request): Matricula
    {
        return $this->atualizarMatricula->executar(
            $matricula,
            MatriculaDTO::fromAtualizarRequest($request),
            auth()->id(),
        );
    }

    public function trancar(Matricula $matricula, string $dataEfeito, string $motivo, bool $confirmarComDividas): Matricula
    {
        return $this->trancarMatricula->executar($matricula, $dataEfeito, $motivo, $confirmarComDividas, auth()->id());
    }

    public function reactivar(Matricula $matricula, string $dataEfeito, string $motivo): Matricula
    {
        return $this->reactivarMatricula->executar($matricula, $dataEfeito, $motivo, auth()->id());
    }

    /** @return array{bloqueios: list<array>, avisos: list<array>} */
    public function verificarTrancamento(Matricula $matricula, string $dataEfeito): array
    {
        return $this->trancarMatricula->verificar($matricula, \Carbon\CarbonImmutable::parse($dataEfeito))->toArray();
    }

    public function alterarEstado(
        Matricula $matricula,
        EstadoMatriculaEnum $novoEstado,
        ?string $dataFim = null,
    ): Matricula {
        return $this->alterarEstadoMatricula->executar(
            $matricula,
            $novoEstado,
            auth()->id(),
            $dataFim,
        );
    }

    public function renovar(Matricula $matricula, ?int $turmaId = null): Matricula
    {
        return $this->renovarMatricula->executar(
            $matricula,
            $turmaId,
            auth()->id(),
        );
    }

    public function eliminar(Matricula $matricula): void
    {
        $this->eliminarMatricula->executar($matricula);
    }

    /**
     * @param  int[]  $matriculaIds
     * @return array{sucesso: int, falhas: array<int, string>}
     */
    public function renovarEmMassa(array $matriculaIds): array
    {
        return $this->renovarMatriculasEmMassa->executar($matriculaIds, auth()->id());
    }
}
