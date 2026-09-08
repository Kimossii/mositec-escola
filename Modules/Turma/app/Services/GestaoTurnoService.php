<?php

namespace Modules\Turma\Services;

use Modules\Core\Enums\Estado;
use Modules\Turma\Actions\AdicionarHorarioTurnoAction;
use Modules\Turma\Actions\AlterarEstadoTurnoAction;
use Modules\Turma\Actions\AtualizarTurnoAction;
use Modules\Turma\Actions\CriarTurnoAction;
use Modules\Turma\Actions\EliminarTurnoAction;
use Modules\Turma\DTO\AdicionarHorarioTurnoDTO;
use Modules\Turma\DTO\TurnoDTO;
use Modules\Turma\Http\Requests\AdicionarHorarioTurnoRequest;
use Modules\Turma\Http\Requests\AtualizarTurnoRequest;
use Modules\Turma\Http\Requests\CriarTurnoRequest;
use Modules\Turma\Models\Turno;
use Modules\Turma\Models\TurnoHorario;

class GestaoTurnoService
{
    public function __construct(
        private CriarTurnoAction $criarTurno,
        private AtualizarTurnoAction $atualizarTurno,
        private AlterarEstadoTurnoAction $alterarEstadoTurno,
        private EliminarTurnoAction $eliminarTurno,
        private AdicionarHorarioTurnoAction $adicionarHorarioTurno,
    ) {}

    public function criar(CriarTurnoRequest $request): Turno
    {
        return $this->criarTurno->executar(TurnoDTO::fromCriarRequest($request));
    }

    public function atualizar(Turno $turno, AtualizarTurnoRequest $request): Turno
    {
        return $this->atualizarTurno->executar($turno, TurnoDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(Turno $turno, Estado $novoEstado): Turno
    {
        return $this->alterarEstadoTurno->executar($turno, $novoEstado);
    }

    public function eliminar(Turno $turno): void
    {
        $this->eliminarTurno->executar($turno);
    }

    public function adicionarHorario(Turno $turno, AdicionarHorarioTurnoRequest $request): TurnoHorario
    {
        return $this->adicionarHorarioTurno->executar($turno, AdicionarHorarioTurnoDTO::fromRequest($request));
    }
}
