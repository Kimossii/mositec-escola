<?php

namespace Modules\Matricula\Services;

use Modules\Aluno\Models\Aluno;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
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

    public function alterarEstado(
        Matricula $matricula,
        EstadoMatriculaEnum $novoEstado
    ): Matricula {
        return $this->alterarEstadoMatricula->executar(
            $matricula,
            $novoEstado,
            auth()->id(),
        );
    }
}
