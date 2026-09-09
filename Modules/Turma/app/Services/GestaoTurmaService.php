<?php

namespace Modules\Turma\Services;

use Modules\Core\Enums\Estado;
use Modules\Turma\Actions\AlterarEstadoTurmaAction;
use Modules\Turma\Actions\AssociarSalaTurmaAction;
use Modules\Turma\Actions\AtualizarSalaTurmaAction;
use Modules\Turma\Actions\AtualizarTurmaAction;
use Modules\Turma\Actions\CriarTurmaAction;
use Modules\Turma\Actions\EliminarTurmaAction;
use Modules\Turma\Actions\EncerrarSalaTurmaAction;
use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\DTO\TurmaSalaDTO;
use Modules\Turma\Http\Requests\AssociarSalaTurmaRequest;
use Modules\Turma\Http\Requests\AtualizarSalaTurmaRequest;
use Modules\Turma\Http\Requests\AtualizarTurmaRequest;
use Modules\Turma\Http\Requests\CriarTurmaRequest;
use Modules\Turma\Http\Requests\EncerrarSalaTurmaRequest;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\TurmaSala;

class GestaoTurmaService
{
    public function __construct(
        private CriarTurmaAction $criarTurma,
        private AtualizarTurmaAction $atualizarTurma,
        private AlterarEstadoTurmaAction $alterarEstadoTurma,
        private EliminarTurmaAction $eliminarTurma,
        private AssociarSalaTurmaAction $associarSalaTurma,
        private AtualizarSalaTurmaAction $atualizarSalaTurma,
        private EncerrarSalaTurmaAction $encerrarSalaTurma,
    ) {}

    public function criar(CriarTurmaRequest $request): Turma
    {
        return $this->criarTurma->executar(TurmaDTO::fromCriarRequest($request));
    }

    public function atualizar(Turma $turma, AtualizarTurmaRequest $request): Turma
    {
        return $this->atualizarTurma->executar($turma, TurmaDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(Turma $turma, Estado $novoEstado): Turma
    {
        return $this->alterarEstadoTurma->executar($turma, $novoEstado);
    }

    public function eliminar(Turma $turma): void
    {
        $this->eliminarTurma->executar($turma);
    }

    public function associarSala(Turma $turma, AssociarSalaTurmaRequest $request): TurmaSala
    {
        return $this->associarSalaTurma->executar($turma, TurmaSalaDTO::fromAssociarRequest($request));
    }

    public function atualizarSala(TurmaSala $turmaSala, AtualizarSalaTurmaRequest $request): TurmaSala
    {
        return $this->atualizarSalaTurma->executar($turmaSala, TurmaSalaDTO::fromAtualizarRequest($request));
    }

    public function encerrarSala(TurmaSala $turmaSala, EncerrarSalaTurmaRequest $request): TurmaSala
    {
        return $this->encerrarSalaTurma->executar($turmaSala, TurmaSalaDTO::fromEncerrarRequest($request));
    }
}
