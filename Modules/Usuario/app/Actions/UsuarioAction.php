<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Models\Role;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\GeradorMatriculaService;

class UsuarioAction
{
    public function __construct(
        private GeradorMatriculaService $geradorMatricula,
        private SincronizarPermissoesUtilizadorAction $sincronizarPermissoes,
    ) {}

    public function criar(UsuarioDTO $dto): User
    {
        return DB::transaction(function () use ($dto) {
            $user = User::create([
                'name' => $dto->name,
                'email' => $dto->tipoLogin === TipoLogin::EMAIL ? $dto->email : null,
                'numero_matricula' => $dto->tipoLogin === TipoLogin::MATRICULA
                    ? $this->geradorMatricula->gerar()
                    : null,
                'tipo_login' => $dto->tipoLogin,
                'password' => Hash::make($dto->password),
                'dados_pessoa_id' => $dto->dados_pessoa_id,
                'estado' => $dto->estado->value,
            ]);

            $role = Role::where('nome', $dto->perfil->value)->firstOrFail();
            $user->roles()->attach($role->id);

            $this->sincronizarPermissoes->executar($user, $dto->celulas);

            if (! empty($dto->matriculasEducandos)) {
                $alunosIds = User::whereIn('numero_matricula', $dto->matriculasEducandos)->pluck('id');
                $user->educandos()->attach($alunosIds);
            }

            return $user;
        });
    }
}
