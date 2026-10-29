<?php

namespace Modules\Docente\DTO;

use Modules\Docente\Http\Requests\AtualizarDocenteRequest;
use Modules\Docente\Http\Requests\CriarDocenteRequest;

/** Dados pessoais do docente (ficam em `DadosPessoa`). Sem conta: email e senha de acesso não entram aqui. */
class DocenteDTO
{
    public function __construct(
        public string $nomeCompleto,
        public string $numeroIdentificacao,
        public ?string $email = null,
        public ?string $telefone = null,
        public ?string $dataNascimento = null,
        public int $sexo = 0,
        public ?string $telefoneAlternativo = null,
    ) {
    }

    public static function fromCriarRequest(CriarDocenteRequest $request): self
    {
        return self::deArray($request->validated());
    }

    public static function fromAtualizarRequest(AtualizarDocenteRequest $request): self
    {
        return self::deArray($request->validated());
    }

    /** @param  array<string, mixed>  $dados */
    private static function deArray(array $dados): self
    {
        return new self(
            nomeCompleto: $dados['nome_completo'],
            numeroIdentificacao: $dados['numero_identificacao'],
            email: $dados['email'] ?? null,
            telefone: $dados['telefone'] ?? null,
            dataNascimento: $dados['data_nascimento'] ?? null,
            sexo: (int) ($dados['sexo'] ?? 0),
            telefoneAlternativo: $dados['telefone_alternativo'] ?? null,
        );
    }
}
