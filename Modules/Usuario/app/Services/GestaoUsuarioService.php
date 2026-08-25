<?php

namespace Modules\Usuario\Services;

use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
use Modules\Usuario\Models\User;

class GestaoUsuarioService
{
    public function __construct(
        private UsuarioAction $action,
    ) {}

    public function criar(CriarUsuarioRequest $request): User
    {
        $dto = UsuarioDTO::fromArray($request->validated());

        return $this->action->criar($dto);
    }
}
