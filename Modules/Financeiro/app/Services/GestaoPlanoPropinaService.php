<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarEstadoPlanoPropinaAction;
use Modules\Financeiro\Actions\AtualizarPlanoPropinaAction;
use Modules\Financeiro\Actions\CopiarPlanosPropinaAction;
use Modules\Financeiro\Actions\CriarPlanoPropinaAction;
use Modules\Financeiro\Actions\EliminarPlanoPropinaAction;
use Modules\Financeiro\DTO\CopiarPlanosPropinaDTO;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\DTO\ResumoCopiaPlanosDTO;
use Modules\Financeiro\Http\Requests\AtualizarPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\CopiarPlanosPropinaRequest;
use Modules\Financeiro\Http\Requests\CriarPlanoPropinaRequest;
use Modules\Financeiro\Models\PlanoPropina;

class GestaoPlanoPropinaService
{
    public function __construct(
        private CriarPlanoPropinaAction $criarAction,
        private AtualizarPlanoPropinaAction $atualizarAction,
        private AlterarEstadoPlanoPropinaAction $alterarEstadoAction,
        private EliminarPlanoPropinaAction $eliminarAction,
        private CopiarPlanosPropinaAction $copiarAction,
    ) {
    }

    public function criar(CriarPlanoPropinaRequest $request): PlanoPropina
    {
        return $this->criarAction->executar(PlanoPropinaDTO::fromRequest($request));
    }

    public function atualizar(PlanoPropina $plano, AtualizarPlanoPropinaRequest $request): PlanoPropina
    {
        return $this->atualizarAction->executar($plano, PlanoPropinaDTO::fromRequest($request));
    }

    public function alterarEstado(PlanoPropina $plano, Estado $estado): PlanoPropina
    {
        return $this->alterarEstadoAction->executar($plano, $estado);
    }

    public function eliminar(PlanoPropina $plano): void
    {
        $this->eliminarAction->executar($plano);
    }

    public function copiar(CopiarPlanosPropinaRequest $request): ResumoCopiaPlanosDTO
    {
        return $this->copiarAction->executar(CopiarPlanosPropinaDTO::fromRequest($request));
    }
}
