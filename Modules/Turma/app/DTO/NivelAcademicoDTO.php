<?php

namespace Modules\Turma\DTO;

use Modules\Turma\Http\Requests\AtualizarNivelAcademicoRequest;
use Modules\Turma\Http\Requests\CriarNivelAcademicoRequest;

class NivelAcademicoDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public int $ordem,
        public ?int $estabelecimento_id = null,
    ) {
    }

    public static function fromCriarRequest(
        CriarNivelAcademicoRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            ordem: (int) $dados['ordem'],
            estabelecimento_id: (int) $dados['estabelecimento_id'],
        );
    }

    public static function fromAtualizarRequest(
        AtualizarNivelAcademicoRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            ordem: (int) $dados['ordem'],
        );
    }
}
