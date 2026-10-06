<?php

namespace Modules\Turma\DTO;

use Modules\Turma\Http\Requests\CriarTurnoRequest;
use Modules\Turma\Http\Requests\AtualizarTurnoRequest;

class TurnoDTO
{
    public function __construct(
        public string $nome,
        public ?string $descricao = null,
    ) {
    }

    public static function fromCriarRequest(CriarTurnoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarTurnoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }
}
