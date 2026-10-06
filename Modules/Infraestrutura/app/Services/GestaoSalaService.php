<?php

namespace Modules\Infraestrutura\Services;

use Modules\Infraestrutura\Actions\AlterarEstadoSalaAction;
use Modules\Infraestrutura\Actions\AtualizarSalaAction;
use Modules\Infraestrutura\Actions\CriarSalaAction;
use Modules\Infraestrutura\Actions\EliminarSalaAction;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Http\Requests\AtualizarSalaRequest;
use Modules\Infraestrutura\Http\Requests\CriarSalaRequest;
use Modules\Infraestrutura\Models\Sala;

class GestaoSalaService
{
    public function __construct(
        private CriarSalaAction $criarSala,
        private AtualizarSalaAction $atualizarSala,
        private AlterarEstadoSalaAction $alterarEstadoSala,
        private EliminarSalaAction $eliminarSala,
    ) {}

    public function criar(CriarSalaRequest $request): Sala
    {
        return $this->criarSala->criar(SalaDTO::fromRequest($request));
    }

    public function atualizar(Sala $sala, AtualizarSalaRequest $request): Sala
    {
        return $this->atualizarSala->atualizar($sala, SalaDTO::fromRequest($request));
    }

    public function alterarEstado(Sala $sala, EstadoSala $novoEstado): Sala
    {
        return $this->alterarEstadoSala->alterar($sala, $novoEstado);
    }

    public function eliminar(Sala $sala): void
    {
        $this->eliminarSala->executar($sala);
    }
}
