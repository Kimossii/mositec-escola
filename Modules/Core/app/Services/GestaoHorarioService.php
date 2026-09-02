<?php

namespace Modules\Core\Services;

use Modules\Core\Actions\Horario\AtualizarHorarioAction;
use Modules\Core\Actions\Horario\CriarHorarioAction;
use Modules\Core\Actions\Horario\EliminarHorarioAction;
use Modules\Core\DTO\HorarioDTO;
use Modules\Core\Http\Requests\Horario\AtualizarHorarioRequest;
use Modules\Core\Http\Requests\Horario\CriarHorarioRequest;
use Modules\Core\Models\Horario;

class GestaoHorarioService
{
    public function __construct(
        private CriarHorarioAction $criarHorario,
        private AtualizarHorarioAction $atualizarHorario,
        private EliminarHorarioAction $eliminarHorario,
    ) {}

    public function criar(CriarHorarioRequest $request): Horario
    {
        return $this->criarHorario->criar(HorarioDTO::fromRequest($request));
    }

    public function atualizar(Horario $horario, AtualizarHorarioRequest $request): Horario
    {
        return $this->atualizarHorario->atualizar($horario, HorarioDTO::fromRequest($request));
    }

    public function eliminar(Horario $horario): void
    {
        $this->eliminarHorario->executar($horario);
    }
}
