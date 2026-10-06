<?php

namespace Modules\Matricula\DTO;

use Modules\Matricula\Http\Requests\AtualizarMatriculaRequest;
use Modules\Matricula\Http\Requests\CriarMatriculaRequest;

class MatriculaDTO
{
    public function __construct(
        public ?int $turmaId,
        public ?int $anoLectivoId,
        public ?string $dataMatricula,
        public ?int $estado,
        public ?string $observacoes,
    ) {
    }

    public static function fromCriarRequest(CriarMatriculaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            turmaId: $dados['turma_id'],
            anoLectivoId: $dados['ano_lectivo_id'],
            dataMatricula: $dados['data_matricula'] ?? null,
            estado: $dados['estado'] ?? null,
            observacoes: $dados['observacoes'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarMatriculaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            turmaId: $dados['turma_id'],
            anoLectivoId: $dados['ano_lectivo_id'],
            dataMatricula: $dados['data_matricula'],
            estado: $dados['estado'] ?? null,
            observacoes: $dados['observacoes'] ?? null,
        );
    }
}
