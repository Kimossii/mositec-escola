<?php

namespace Modules\Turma\Services;

use Modules\Core\Enums\Estado;
use Modules\Turma\Actions\AlterarEstadoNivelAcademicoAction;
use Modules\Turma\Actions\AtualizarNivelAcademicoAction;
use Modules\Turma\Actions\CriarNivelAcademicoAction;
use Modules\Turma\Actions\EliminarNivelAcademicoAction;
use Modules\Turma\DTO\NivelAcademicoDTO;
use Modules\Turma\Http\Requests\AtualizarNivelAcademicoRequest;
use Modules\Turma\Http\Requests\CriarNivelAcademicoRequest;
use Modules\Turma\Models\NivelAcademico;

class GestaoNivelAcademicoService
{
    public function __construct(
        private CriarNivelAcademicoAction $criarNivelAcademico,
        private AtualizarNivelAcademicoAction $atualizarNivelAcademico,
        private AlterarEstadoNivelAcademicoAction $alterarEstadoNivelAcademico,
        private EliminarNivelAcademicoAction $eliminarNivelAcademico,
    ) {}

    public function criar(CriarNivelAcademicoRequest $request): NivelAcademico
    {
        return $this->criarNivelAcademico->executar(NivelAcademicoDTO::fromCriarRequest($request));
    }

    public function atualizar(NivelAcademico $nivelAcademico, AtualizarNivelAcademicoRequest $request): NivelAcademico
    {
        return $this->atualizarNivelAcademico->executar($nivelAcademico, NivelAcademicoDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(NivelAcademico $nivelAcademico, Estado $novoEstado): NivelAcademico
    {
        return $this->alterarEstadoNivelAcademico->executar($nivelAcademico, $novoEstado);
    }

    public function eliminar(NivelAcademico $nivelAcademico): void
    {
        $this->eliminarNivelAcademico->executar($nivelAcademico);
    }
}
