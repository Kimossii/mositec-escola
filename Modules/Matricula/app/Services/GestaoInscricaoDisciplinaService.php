<?php

namespace Modules\Matricula\Services;

use Modules\Matricula\Actions\AlterarEstadoInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Actions\EliminarInscricaoDisciplinaAction;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Http\Requests\CriarInscricaoDisciplinaRequest;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class GestaoInscricaoDisciplinaService
{
    public function __construct(
        private CriarInscricaoDisciplinaAction $criarInscricao,
        private AlterarEstadoInscricaoDisciplinaAction $alterarEstadoInscricao,
        private EliminarInscricaoDisciplinaAction $eliminarInscricao,
    ) {
    }

    public function criar(Matricula $matricula, CriarInscricaoDisciplinaRequest $request): InscricaoDisciplina
    {
        $planoCurricularDisciplina = PlanoCurricularDisciplina::findOrFail($request->validated('plano_curricular_disciplina_id'));

        return $this->criarInscricao->executar($matricula, $planoCurricularDisciplina, auth()->id());
    }

    public function alterarEstado(InscricaoDisciplina $inscricao, EstadoInscricaoDisciplinaEnum $novoEstado): InscricaoDisciplina
    {
        return $this->alterarEstadoInscricao->executar($inscricao, $novoEstado, auth()->id());
    }

    public function eliminar(InscricaoDisciplina $inscricao): void
    {
        $this->eliminarInscricao->executar($inscricao);
    }
}
