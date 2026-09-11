<?php

namespace Modules\Turma\DTO;

use Modules\Turma\Http\Requests\AtualizarTurmaRequest;
use Modules\Turma\Http\Requests\CriarTurmaRequest;

class TurmaDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public ?int $ano_lectivo_id = null,
        public ?int $nivel_academico_id = null,
        public ?int $curso_id = null,
        public ?int $turno_id = null,
    ) {
    }

    public static function fromCriarRequest(
        CriarTurmaRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            ano_lectivo_id: (int) $dados['ano_lectivo_id'],
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            curso_id: isset($dados['curso_id']) ? (int) $dados['curso_id'] : null,
            turno_id: isset($dados['turno_id'])
            ? (int) $dados['turno_id']
            : null,
        );
    }

    public static function fromAtualizarRequest(
        AtualizarTurmaRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            curso_id: isset($dados['curso_id']) ? (int) $dados['curso_id'] : null,
            turno_id: isset($dados['turno_id'])
            ? (int) $dados['turno_id']
            : null,
        );
    }
}
