<?php

namespace Modules\AnoLectivo\Services;

use Modules\AnoLectivo\Actions\AlterarEstadoAnoLectivoAction;
use Modules\AnoLectivo\Actions\AtualizarAnoLectivoAction;
use Modules\AnoLectivo\Actions\AtualizarEventoCalendarioAction;
use Modules\AnoLectivo\Actions\AtualizarPeriodoAction;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarEventoCalendarioAction;
use Modules\AnoLectivo\Actions\CriarPeriodoAction;
use Modules\AnoLectivo\Actions\EliminarAnoLectivoAction;
use Modules\AnoLectivo\Actions\EliminarEventoCalendarioAction;
use Modules\AnoLectivo\Actions\EliminarPeriodoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Http\Requests\AtualizarAnoLectivoRequest;
use Modules\AnoLectivo\Http\Requests\AtualizarEventoCalendarioRequest;
use Modules\AnoLectivo\Http\Requests\AtualizarPeriodoRequest;
use Modules\AnoLectivo\Http\Requests\CriarAnoLectivoRequest;
use Modules\AnoLectivo\Http\Requests\CriarEventoCalendarioRequest;
use Modules\AnoLectivo\Http\Requests\CriarPeriodoRequest;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Models\Periodo;

class GestaoAnoLectivoService
{
    public function __construct(
        private CriarAnoLectivoAction $criarAnoLectivo,
        private AtualizarAnoLectivoAction $atualizarAnoLectivo,
        private AlterarEstadoAnoLectivoAction $alterarEstadoAnoLectivo,
        private EliminarAnoLectivoAction $eliminarAnoLectivo,
        private CriarPeriodoAction $criarPeriodo,
        private AtualizarPeriodoAction $atualizarPeriodo,
        private EliminarPeriodoAction $eliminarPeriodo,
        private CriarEventoCalendarioAction $criarEvento,
        private AtualizarEventoCalendarioAction $atualizarEvento,
        private EliminarEventoCalendarioAction $eliminarEvento,
    ) {}

    public function criar(CriarAnoLectivoRequest $request): AnoLectivo
    {
        return $this->criarAnoLectivo->criar(AnoLectivoDTO::fromRequest($request));
    }

    public function atualizar(AnoLectivo $anoLectivo, AtualizarAnoLectivoRequest $request): AnoLectivo
    {
        return $this->atualizarAnoLectivo->atualizar($anoLectivo, AnoLectivoDTO::fromRequest($request));
    }

    public function alterarEstado(AnoLectivo $anoLectivo, EstadoAnoLectivo $novoEstado): AnoLectivo
    {
        return $this->alterarEstadoAnoLectivo->alterar($anoLectivo, $novoEstado);
    }

    public function eliminar(AnoLectivo $anoLectivo): void
    {
        $this->eliminarAnoLectivo->executar($anoLectivo);
    }

    public function criarPeriodo(AnoLectivo $anoLectivo, CriarPeriodoRequest $request): Periodo
    {
        return $this->criarPeriodo->criar($anoLectivo, PeriodoDTO::fromRequest($request));
    }

    public function atualizarPeriodo(Periodo $periodo, AtualizarPeriodoRequest $request): Periodo
    {
        return $this->atualizarPeriodo->atualizar($periodo, PeriodoDTO::fromRequest($request));
    }

    public function eliminarPeriodo(Periodo $periodo): void
    {
        $this->eliminarPeriodo->executar($periodo);
    }

    public function criarEventoCalendario(AnoLectivo $anoLectivo, CriarEventoCalendarioRequest $request): EventoCalendario
    {
        return $this->criarEvento->criar($anoLectivo, EventoCalendarioDTO::fromRequest($request));
    }

    public function atualizarEventoCalendario(EventoCalendario $evento, AtualizarEventoCalendarioRequest $request): EventoCalendario
    {
        return $this->atualizarEvento->atualizar($evento, EventoCalendarioDTO::fromRequest($request));
    }

    public function eliminarEventoCalendario(EventoCalendario $evento): void
    {
        $this->eliminarEvento->executar($evento);
    }
}
