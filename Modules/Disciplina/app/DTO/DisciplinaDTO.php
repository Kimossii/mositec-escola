<?php

namespace Modules\Disciplina\DTO;

use Modules\Disciplina\Http\Requests\AtualizarDisciplinaRequest;
use Modules\Disciplina\Http\Requests\CriarDisciplinaRequest;

class DisciplinaDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public ?string $descricao = null,
    ) {
    }

    public static function fromCriarRequest(CriarDisciplinaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarDisciplinaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }
}
