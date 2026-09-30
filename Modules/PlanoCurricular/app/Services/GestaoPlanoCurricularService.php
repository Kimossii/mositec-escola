<?php

namespace Modules\PlanoCurricular\Services;

use Modules\Core\Enums\Estado;
use Modules\PlanoCurricular\Actions\AdicionarDisciplinaAoPlanoAction;
use Modules\PlanoCurricular\Actions\AlterarEstadoPlanoCurricularAction;
use Modules\PlanoCurricular\Actions\AtualizarDisciplinaDoPlanoAction;
use Modules\PlanoCurricular\Actions\AtualizarPlanoCurricularAction;
use Modules\PlanoCurricular\Actions\ConfirmarPlanoParaAnoLectivoAction;
use Modules\PlanoCurricular\Actions\CriarPlanoCurricularAction;
use Modules\PlanoCurricular\Actions\DefinirPeriodosDaDisciplinaAction;
use Modules\PlanoCurricular\Actions\RemoverDisciplinaDoPlanoAction;
use Illuminate\Support\Collection;
use Modules\PlanoCurricular\DTO\ConfirmarAnoLectivoDTO;
use Modules\PlanoCurricular\DTO\DefinirPeriodosDisciplinaDTO;
use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Http\Requests\AdicionarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\ConfirmarAnoLectivoRequest;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\DefinirPeriodosDisciplinaRequest;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class GestaoPlanoCurricularService
{
    public function __construct(
        private CriarPlanoCurricularAction $criarPlano,
        private AtualizarPlanoCurricularAction $atualizarPlano,
        private AlterarEstadoPlanoCurricularAction $alterarEstadoPlano,
        private AdicionarDisciplinaAoPlanoAction $adicionarDisciplina,
        private AtualizarDisciplinaDoPlanoAction $atualizarDisciplina,
        private RemoverDisciplinaDoPlanoAction $removerDisciplina,
        private ConfirmarPlanoParaAnoLectivoAction $confirmarAnoLectivo,
        private DefinirPeriodosDaDisciplinaAction $definirPeriodosDisciplina,
    ) {
    }

    public function criar(CriarPlanoCurricularRequest $request): PlanoCurricular
    {
        return $this->criarPlano->executar(PlanoCurricularDTO::fromCriarRequest($request));
    }

    public function atualizar(PlanoCurricular $plano, AtualizarPlanoCurricularRequest $request): PlanoCurricular
    {
        return $this->atualizarPlano->executar($plano, PlanoCurricularDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(PlanoCurricular $plano, Estado $novoEstado): PlanoCurricular
    {
        return $this->alterarEstadoPlano->executar($plano, $novoEstado);
    }

    public function adicionarDisciplina(PlanoCurricular $plano, AdicionarDisciplinaRequest $request): PlanoCurricularDisciplina
    {
        return $this->adicionarDisciplina->executar($plano, PlanoCurricularDisciplinaDTO::fromAdicionarRequest($request));
    }

    public function atualizarDisciplina(PlanoCurricularDisciplina $item, AtualizarDisciplinaRequest $request): PlanoCurricularDisciplina
    {
        return $this->atualizarDisciplina->executar($item, PlanoCurricularDisciplinaDTO::fromAtualizarRequest($request));
    }

    public function removerDisciplina(PlanoCurricularDisciplina $item): void
    {
        $this->removerDisciplina->executar($item);
    }

    public function confirmarAnoLectivo(PlanoCurricular $plano, ConfirmarAnoLectivoRequest $request, int $confirmadoPorId): PlanoCurricularAnoLectivo
    {
        return $this->confirmarAnoLectivo->executar($plano, ConfirmarAnoLectivoDTO::fromRequest($request), $confirmadoPorId);
    }

    public function definirPeriodosDisciplina(PlanoCurricularAnoLectivo $aplicacao, PlanoCurricularDisciplina $disciplina, DefinirPeriodosDisciplinaRequest $request): Collection
    {
        return $this->definirPeriodosDisciplina->executar($aplicacao, $disciplina, DefinirPeriodosDisciplinaDTO::fromRequest($request));
    }
}
