<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Http\Requests\AtualizarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;

class PlanoCurricularDTO
{
    public function __construct(
        public int $curso_id,
        public string $codigo,
        public string $nome,
        public ?string $descricao = null,
    ) {
    }

    public static function fromCriarRequest(CriarPlanoCurricularRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            curso_id: (int) $dados['curso_id'],
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarPlanoCurricularRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            curso_id: (int) $dados['curso_id'],
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }
}
