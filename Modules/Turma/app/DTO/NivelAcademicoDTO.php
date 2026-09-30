<?php

namespace Modules\Turma\DTO;

use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Turma\Http\Requests\AtualizarNivelAcademicoRequest;
use Modules\Turma\Http\Requests\CriarNivelAcademicoRequest;

class NivelAcademicoDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public int $ordem,
        public EtapaEnsinoEnum $etapa_ensino,
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
            etapa_ensino: EtapaEnsinoEnum::from((int) $dados['etapa_ensino']),
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
            etapa_ensino: EtapaEnsinoEnum::from((int) $dados['etapa_ensino']),
        );
    }
}
