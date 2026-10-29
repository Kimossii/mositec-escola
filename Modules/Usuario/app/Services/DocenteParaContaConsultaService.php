<?php

namespace Modules\Usuario\Services;

use Modules\Core\Contracts\ProcuraDocenteParaConta;
use Modules\Core\DTO\DocenteParaConta;
use Modules\Usuario\Models\User;

/**
 * Leitura para criar a conta de um docente: procura o docente (via contrato do Core, já filtrado pelo tenant), diz se
 * já tem conta e lista os docentes sem conta para o formulário de novo professor.
 */
class DocenteParaContaConsultaService
{
    public function __construct(private ProcuraDocenteParaConta $procuraDocente) {}

    public function procurar(int $docenteId): ?DocenteParaConta
    {
        return $this->procuraDocente->procurar($docenteId);
    }

    public function jaTemConta(DocenteParaConta $docente): bool
    {
        return User::where('dados_pessoa_id', $docente->dadosPessoaId)->exists();
    }

    public function emailEmUso(string $email): bool
    {
        return User::where('email', $email)->exists();
    }

    /**
     * Só o que o ecrã mostra. Número de identificação e email de contacto só vão se `$comDadosDeIdentificacao`
     * (decidido no servidor: o utilizador tem docente.ver).
     *
     * @return list<array<string, mixed>>
     */
    public function semConta(string $pesquisa, bool $comDadosDeIdentificacao = false): array
    {
        return array_map(
            fn (DocenteParaConta $d) => ['id' => $d->id, 'nome' => $d->nome] + ($comDadosDeIdentificacao
                ? ['numero_identificacao' => $d->numeroIdentificacao, 'email' => $d->email]
                : []),
            $this->procuraDocente->semConta($pesquisa),
        );
    }
}
