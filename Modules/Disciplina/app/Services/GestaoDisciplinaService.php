<?php

namespace Modules\Disciplina\Services;

use Modules\Core\Enums\Estado;
use Modules\Disciplina\Actions\AlterarEstadoDisciplinaAction;
use Modules\Disciplina\Actions\AtualizarDisciplinaAction;
use Modules\Disciplina\Actions\CriarDisciplinaAction;
use Modules\Disciplina\DTO\DisciplinaDTO;
use Modules\Disciplina\Http\Requests\AtualizarDisciplinaRequest;
use Modules\Disciplina\Http\Requests\CriarDisciplinaRequest;
use Modules\Disciplina\Models\Disciplina;

class GestaoDisciplinaService
{
    public function __construct(
        private CriarDisciplinaAction $criarDisciplina,
        private AtualizarDisciplinaAction $atualizarDisciplina,
        private AlterarEstadoDisciplinaAction $alterarEstadoDisciplina,
    ) {
    }

    public function criar(CriarDisciplinaRequest $request): Disciplina
    {
        return $this->criarDisciplina->executar(DisciplinaDTO::fromCriarRequest($request));
    }

    public function atualizar(Disciplina $disciplina, AtualizarDisciplinaRequest $request): Disciplina
    {
        return $this->atualizarDisciplina->executar($disciplina, DisciplinaDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(Disciplina $disciplina, Estado $novoEstado): Disciplina
    {
        return $this->alterarEstadoDisciplina->executar($disciplina, $novoEstado);
    }
}
