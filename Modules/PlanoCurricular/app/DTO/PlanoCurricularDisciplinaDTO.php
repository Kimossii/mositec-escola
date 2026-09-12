<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Http\Requests\AdicionarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarDisciplinaRequest;

class PlanoCurricularDisciplinaDTO
{
    public function __construct(
        public int $disciplina_id,
        public TipoDisciplinaPlano $tipo,
        public bool $obrigatoria,
        public int $ordem,
        public ?int $carga_horaria = null,
        public ?int $creditos = null,
        public ?ComponentePlanoCurricular $componente = null,
    ) {
    }

    public static function fromAdicionarRequest(AdicionarDisciplinaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            disciplina_id: (int) $dados['disciplina_id'],
            tipo: TipoDisciplinaPlano::from((int) $dados['tipo']),
            obrigatoria: (bool) $dados['obrigatoria'],
            ordem: (int) $dados['ordem'],
            carga_horaria: isset($dados['carga_horaria']) ? (int) $dados['carga_horaria'] : null,
            creditos: isset($dados['creditos']) ? (int) $dados['creditos'] : null,
            componente: isset($dados['componente']) ? ComponentePlanoCurricular::from((int) $dados['componente']) : null,
        );
    }

    public static function fromAtualizarRequest(AtualizarDisciplinaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            disciplina_id: (int) $dados['disciplina_id'],
            tipo: TipoDisciplinaPlano::from((int) $dados['tipo']),
            obrigatoria: (bool) $dados['obrigatoria'],
            ordem: (int) $dados['ordem'],
            carga_horaria: isset($dados['carga_horaria']) ? (int) $dados['carga_horaria'] : null,
            creditos: isset($dados['creditos']) ? (int) $dados['creditos'] : null,
            componente: isset($dados['componente']) ? ComponentePlanoCurricular::from((int) $dados['componente']) : null,
        );
    }
}
