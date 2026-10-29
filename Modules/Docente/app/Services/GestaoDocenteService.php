<?php

namespace Modules\Docente\Services;

use Modules\Docente\Actions\AlterarEstadoDocenteAction;
use Modules\Docente\Actions\AtualizarDocenteAction;
use Modules\Docente\Actions\CriarDocenteAction;
use Modules\Docente\Actions\EliminarDocenteAction;
use Modules\Docente\DTO\DocenteDTO;
use Modules\Docente\Http\Requests\AtualizarDocenteRequest;
use Modules\Docente\Http\Requests\CriarDocenteRequest;
use Modules\Docente\Http\Requests\CriarAcessoDocenteRequest;
use Modules\Docente\Models\Docente;
use Modules\Usuario\Actions\CriarUtilizadorDocenteAction;
use Modules\Usuario\Models\User;

class GestaoDocenteService
{
    public function __construct(
        private CriarDocenteAction $criarDocente,
        private AtualizarDocenteAction $atualizarDocente,
        private AlterarEstadoDocenteAction $alterarEstadoDocente,
        private EliminarDocenteAction $eliminarDocente,
        private CriarUtilizadorDocenteAction $criarAcesso,
    ) {
    }

    public function criar(CriarDocenteRequest $request): Docente
    {
        return $this->criarDocente->executar(DocenteDTO::fromCriarRequest($request));
    }

    public function atualizar(Docente $docente, AtualizarDocenteRequest $request): Docente
    {
        return $this->atualizarDocente->executar($docente, DocenteDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(Docente $docente, int $estado): Docente
    {
        return $this->alterarEstadoDocente->executar($docente, $estado);
    }

    /** «Criar acesso»: a mesma Action do novo professor em Utilizadores → Professores. */
    public function criarAcesso(Docente $docente, CriarAcessoDocenteRequest $request): User
    {
        return $this->criarAcesso->executar($docente->id, $request->validated('email'), $request->validated('password'));
    }

    public function eliminar(Docente $docente): void
    {
        $this->eliminarDocente->executar($docente);
    }
}
