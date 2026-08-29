<?php

namespace Modules\Usuario\Services;

use Modules\Usuario\Actions\AlternarEstadoUsuarioAction;
use Modules\Usuario\Actions\AtualizarUsuarioAction;
use Modules\Usuario\Actions\EliminarUsuarioAction;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
use Modules\Usuario\Models\User;

class GestaoUsuarioService
{
    public function __construct(
        private UsuarioAction $criarAction,
        private AtualizarUsuarioAction $atualizarAction,
        private EliminarUsuarioAction $eliminarAction,
        private AlternarEstadoUsuarioAction $alternarEstadoAction,
    ) {}

    public function criar(CriarUsuarioRequest $request): User
    {
        $dto = UsuarioDTO::fromArray($request->validated());

        return $this->criarAction->criar($dto);
    }

    public function atualizar(User $user, array $dados): User
    {
        return $this->atualizarAction->atualizar($user, $dados);
    }

    public function eliminar(User $user): void
    {
        $this->eliminarAction->executar($user);
    }

    public function alternarEstado(User $user): User
    {
        return $this->alternarEstadoAction->executar($user);
    }
}
