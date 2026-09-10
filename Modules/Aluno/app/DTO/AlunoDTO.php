<?php

namespace Modules\Aluno\DTO;

use Modules\Aluno\Http\Requests\AtualizarAlunoRequest;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;

class AlunoDTO
{
    public function __construct(
        public ?int $dadosPessoaId,
        public ?string $nomeCompleto,
        public ?string $email,
        public ?string $telefone,
        public ?string $dataNascimento,
        public int $sexo,
        public ?string $numeroIdentificacao,
    ) {
    }

    public static function fromCriarRequest(CriarAlunoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            dadosPessoaId: $dados['dados_pessoa_id'] ?? null,
            nomeCompleto: $dados['nome_completo'] ?? null,
            email: $dados['email'] ?? null,
            telefone: $dados['telefone'] ?? null,
            dataNascimento: $dados['data_nascimento'] ?? null,
            sexo: $dados['sexo'] ?? 0,
            numeroIdentificacao: $dados['numero_identificacao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarAlunoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            dadosPessoaId: null,
            nomeCompleto: $dados['nome_completo'],
            email: $dados['email'] ?? null,
            telefone: $dados['telefone'] ?? null,
            dataNascimento: $dados['data_nascimento'] ?? null,
            sexo: $dados['sexo'] ?? 0,
            numeroIdentificacao: null,
        );
    }
}
