<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Licenciamento\Contracts\GarantiaDeLimites;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Events\UtilizadorCriado;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\GeradorMatriculaService;

class UsuarioAction
{
    public function __construct(
        private GeradorMatriculaService $geradorMatricula,
        private GarantiaDeLimites $limites,
    ) {}

    public function criar(UsuarioDTO $dto): User
    {
        return DB::transaction(function () use ($dto) {
            // Contas de aluno têm limite próprio (Recurso::ALUNOS); Administrador e contas inativas não gastam utilizadores.
            if ($dto->estado === Estado::ATIVO && ! in_array($dto->perfil, [Perfil::ALUNO, Perfil::ADMIN_ESCOLA], true)) {
                $this->limites->garantir(Recurso::UTILIZADORES);
            }

            $user = User::create([
                'name' => $dto->name,
                'email' => $dto->tipoLogin === TipoLogin::EMAIL ? $dto->email : null,
                'numero_matricula' => $dto->tipoLogin === TipoLogin::MATRICULA
                    ? ($dto->numeroMatricula ?? $this->geradorMatricula->gerar())
                    : null,
                'tipo_login' => $dto->tipoLogin,
                'password' => Hash::make($dto->password),
                'dados_pessoa_id' => $dto->dados_pessoa_id,
                'estado' => $dto->estado->value,
            ]);

            $role = Role::where('nome', $dto->perfil->value)->firstOrFail();
            $user->roles()->attach($role->id);

            if (! empty($dto->matriculasEducandos)) {
                $alunosIds = User::whereIn('numero_matricula', $dto->matriculasEducandos)->pluck('id');
                $user->educandos()->attach($alunosIds);
            }

            UtilizadorCriado::dispatch($user, $dto->perfil);

            return $user;
        });
    }
}
