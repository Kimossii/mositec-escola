<?php

namespace Modules\Usuario\App\Actions;

use Modules\Usuario\App\DTO\UsuarioDTO;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\User;

class CriarUsuarioAction
{
    public function execute(UsuarioDTO $dto): User
    {
        return User::create([
            'nome' => $dto->nome,
            'email' => $dto->email,
            'password' => Hash::make($dto->password),
            'dados_pessoa_id' => $dto->dados_pessoa_id,
        ]);
    }
}
