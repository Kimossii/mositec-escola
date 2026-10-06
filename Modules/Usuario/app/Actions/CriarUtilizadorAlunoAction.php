<?php

namespace Modules\Usuario\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Exceptions\ContaDeAlunoRecusada;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\AlunoParaContaConsultaService;

/**
 * Cria a conta de um aluno a partir do REGISTO do aluno: o nome, os dados pessoais e o login
 * (número de matrícula oficial) vêm do servidor; nada disso é recebido do cliente. Não gera número de
 * matrícula novo. Só aceita aluno activo, da própria escola e sem conta.
 */
class CriarUtilizadorAlunoAction
{
    public function __construct(
        private AlunoParaContaConsultaService $consulta,
        private UsuarioAction $criarUsuario,
    ) {}

    /**
     * @throws ContaDeAlunoRecusada
     */
    public function executar(string $numeroMatricula, string $password, Estado $estado = Estado::ATIVO): User
    {
        $aluno = $this->consulta->procurar(trim($numeroMatricula)) ?? throw ContaDeAlunoRecusada::naoEncontrado();

        if ($aluno->estado !== Estado::ATIVO->value) {
            throw ContaDeAlunoRecusada::inativo();
        }

        if ($this->consulta->jaTemConta($aluno)) {
            throw ContaDeAlunoRecusada::jaTemConta();
        }

        $dto = new UsuarioDTO(
            name: $aluno->nome,
            password: $password,
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            email: null,
            dados_pessoa_id: $aluno->dadosPessoaId,
            estado: $estado,
            numeroMatricula: $aluno->numeroMatricula,
        );

        try {
            return $this->criarUsuario->criar($dto);
        } catch (UniqueConstraintViolationException) {
            // Pedido concorrente que criou a conta entre a verificação e o insert: o índice único travou-o.
            throw ContaDeAlunoRecusada::jaTemConta();
        }
    }
}
