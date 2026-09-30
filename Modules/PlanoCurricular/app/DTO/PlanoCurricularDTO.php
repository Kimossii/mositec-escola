<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Http\Requests\AtualizarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;

class PlanoCurricularDTO
{
    public function __construct(
        public int $nivel_academico_id,
        public string $codigo,
        public string $nome,
        public ?int $curso_id = null,
        public ?string $descricao = null,
    ) {
    }

    public static function fromCriarRequest(CriarPlanoCurricularRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            curso_id: isset($dados['curso_id']) ? (int) $dados['curso_id'] : null,
            descricao: $dados['descricao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarPlanoCurricularRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            curso_id: isset($dados['curso_id']) ? (int) $dados['curso_id'] : null,
            descricao: $dados['descricao'] ?? null,
        );
    }
}
