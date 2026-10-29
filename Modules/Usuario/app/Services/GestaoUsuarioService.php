<?php

namespace Modules\Usuario\Services;

use Modules\Usuario\Actions\AlternarEstadoUsuarioAction;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Actions\AtualizarUsuarioAction;
use Modules\Usuario\Actions\CriarUtilizadorAlunoAction;
use Modules\Usuario\Actions\CriarUtilizadorDocenteAction;
use Modules\Usuario\Actions\EliminarUsuarioAction;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
use Modules\Usuario\Models\User;

class GestaoUsuarioService
{
    public function __construct(
        private UsuarioAction $criarAction,
        private CriarUtilizadorAlunoAction $criarAlunoAction,
        private CriarUtilizadorDocenteAction $criarDocenteAction,
        private AtualizarUsuarioAction $atualizarAction,
        private EliminarUsuarioAction $eliminarAction,
        private AlternarEstadoUsuarioAction $alternarEstadoAction,
    ) {}

    public function criar(CriarUsuarioRequest $request): User
    {
        $dados = $request->validated();

        // Aluno: nome, dados pessoais e login vêm do registo do aluno, nunca do pedido.
        if (Perfil::fromSlug($dados['perfil']) === Perfil::ALUNO) {
            return $this->criarAlunoAction->executar(
                $dados['numero_matricula'],
                $dados['password'],
                isset($dados['estado']) ? Estado::from((int) $dados['estado']) : Estado::ATIVO,
            );
        }

        // Professor escolhido entre os docentes sem conta: nome e pessoa vêm do registo do docente (mesma Action do botão
        // «Criar acesso» em Pessoal → Docentes).
        if (isset($dados['docente_id']) && Perfil::fromSlug($dados['perfil']) === Perfil::PROFESSOR) {
            return $this->criarDocenteAction->executar(
                (int) $dados['docente_id'],
                $dados['email'],
                $dados['password'],
                isset($dados['estado']) ? Estado::from((int) $dados['estado']) : Estado::ATIVO,
            );
        }

        $dto = UsuarioDTO::fromArray($dados);

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
