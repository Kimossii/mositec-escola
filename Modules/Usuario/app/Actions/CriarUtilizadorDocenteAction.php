<?php

namespace Modules\Usuario\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Core\Enums\Estado;
use Modules\Core\Traits\LogService;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Exceptions\ContaDeDocenteRecusada;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\DocenteParaContaConsultaService;

/**
 * «Criar acesso» de um docente (decisão E6), a única Action dos dois pontos de entrada (botão em Pessoal → Docentes e
 * novo professor em Utilizadores → Professores). O nome e a pessoa vêm do REGISTO do docente, nunca do cliente; do
 * cliente só o email de login e a senha. Perfil Professor e login por email. Só aceita docente activo, da própria escola
 * e sem conta. O registo de docente já existe, por isso o listener de `UtilizadorCriado` (E7) não cria outro.
 */
class CriarUtilizadorDocenteAction
{
    use LogService;

    public function __construct(
        private DocenteParaContaConsultaService $consulta,
        private UsuarioAction $criarUsuario,
    ) {}

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(int $docenteId, string $email, string $password, Estado $estado = Estado::ATIVO): User
    {
        return $this->comLog('Criar acesso de docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($docenteId, $email, $password, $estado));
    }

    /**
     * @throws ContaDeDocenteRecusada
     */
    private function executarSemRegisto(int $docenteId, string $email, string $password, Estado $estado): User
    {
        $docente = $this->consulta->procurar($docenteId) ?? throw ContaDeDocenteRecusada::naoEncontrado();

        if ($docente->estado !== Estado::ATIVO->value) {
            throw ContaDeDocenteRecusada::inativo();
        }

        if ($this->consulta->jaTemConta($docente)) {
            throw ContaDeDocenteRecusada::jaTemConta();
        }

        $email = trim($email);

        if ($this->consulta->emailEmUso($email)) {
            throw ContaDeDocenteRecusada::emailEmUso();
        }

        $dto = new UsuarioDTO(
            name: $docente->nome,
            password: $password,
            perfil: Perfil::PROFESSOR,
            tipoLogin: TipoLogin::EMAIL,
            email: $email,
            dados_pessoa_id: $docente->dadosPessoaId,
            estado: $estado,
        );

        try {
            return $this->criarUsuario->criar($dto);
        } catch (UniqueConstraintViolationException) {
            // Pedido concorrente entre a verificação e o insert: o índice único (pessoa ou email) travou-o.
            throw $this->consulta->jaTemConta($docente) ? ContaDeDocenteRecusada::jaTemConta() : ContaDeDocenteRecusada::emailEmUso();
        }
    }
}
