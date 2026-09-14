<?php

namespace Modules\Matricula\Service;

use Modules\Aluno\Models\Aluno;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

class GestaoMatriculaService
{
    public function __construct(
        private CriarMatriculaAction $criarMatricula,
        private AtualizarMatriculaAction $atualizarMatricula,
        private AlterarEstadoMatriculaAction $alterarEstadoMatricula,
    ) {
    }

    public function criar(Aluno $aluno,CriarMatriculaRequest $request): Matricula
    {
        return $this->criarMatricula->executar($aluno,MatriculaDTO::fromCriarRequest($request));
    }

    public function atualizar(Matricula $matricula,AtualizarMatriculaRequest $request): Matricula
    {
        return $this->atualizarMatricula->executar($matricula,MatriculaDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(
        Matricula $matricula,
        EstadoMatriculaEnum $novoEstado
    ): Matricula {
        return $this->alterarEstadoMatricula->executar(
            $matricula,
            $novoEstado
        );
    }
}
