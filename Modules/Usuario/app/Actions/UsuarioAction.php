<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\Hash;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Models\User;

class UsuarioAction
{
    public function criar(UsuarioDTO $dto): User
    {
        return User::create([
            'name' => $dto->name,
            'email' => $dto->email,
            'password' => Hash::make($dto->password),
            'dados_pessoa_id' => $dto->dados_pessoa_id,
            'estado' => $dto->estado->value,
        ]);
    }
}
