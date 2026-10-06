<?php

namespace Modules\Curso\DTO;

use Modules\Curso\Http\Requests\AtualizarCursoRequest;
use Modules\Curso\Http\Requests\CriarCursoRequest;

class CursoDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public ?string $descricao = null,
    ) {
    }

    public static function fromCriarRequest(CriarCursoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarCursoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }
}
