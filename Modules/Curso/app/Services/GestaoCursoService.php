<?php

namespace Modules\Curso\Services;

use Modules\Core\Enums\Estado;
use Modules\Curso\Actions\AlterarEstadoCursoAction;
use Modules\Curso\Actions\AtualizarCursoAction;
use Modules\Curso\Actions\CriarCursoAction;
use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Http\Requests\AtualizarCursoRequest;
use Modules\Curso\Http\Requests\CriarCursoRequest;
use Modules\Curso\Models\Curso;

class GestaoCursoService
{
    public function __construct(
        private CriarCursoAction $criarCurso,
        private AtualizarCursoAction $atualizarCurso,
        private AlterarEstadoCursoAction $alterarEstadoCurso,
    ) {
    }

    public function criar(CriarCursoRequest $request): Curso
    {
        return $this->criarCurso->executar(CursoDTO::fromCriarRequest($request));
    }

    public function atualizar(Curso $curso, AtualizarCursoRequest $request): Curso
    {
        return $this->atualizarCurso->executar($curso, CursoDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(Curso $curso, Estado $novoEstado): Curso
    {
        return $this->alterarEstadoCurso->executar($curso, $novoEstado);
    }
}
