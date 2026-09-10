<?php

namespace Modules\Aluno\Services;

use Modules\Aluno\Actions\AlterarEstadoAlunoAction;
use Modules\Aluno\Actions\AtualizarAlunoAction;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Http\Requests\AtualizarAlunoRequest;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;

class GestaoAlunoService
{
    public function __construct(
        private CriarAlunoAction $criarAluno,
        private AtualizarAlunoAction $atualizarAluno,
        private AlterarEstadoAlunoAction $alterarEstadoAluno,
    ) {
    }

    public function criar(CriarAlunoRequest $request): Aluno
    {
        return $this->criarAluno->executar(AlunoDTO::fromCriarRequest($request));
    }

    public function atualizar(Aluno $aluno, AtualizarAlunoRequest $request): Aluno
    {
        return $this->atualizarAluno->executar($aluno, AlunoDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(Aluno $aluno, Estado $novoEstado): Aluno
    {
        return $this->alterarEstadoAluno->executar($aluno, $novoEstado);
    }
}
