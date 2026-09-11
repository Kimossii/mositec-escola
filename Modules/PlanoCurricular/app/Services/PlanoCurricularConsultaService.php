<?php

namespace Modules\PlanoCurricular\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;

class PlanoCurricularConsultaService
{
    public function listar(): Collection
    {
        return PlanoCurricular::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->with('curso')
            ->orderBy('nome')
            ->get();
    }

    public function opcoesFormulario(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return [
            'cursos' => Curso::where('estabelecimento_id', $estabelecimentoId)->where('estado', Estado::ATIVO->value)->orderBy('nome')->get(['id', 'nome']),
            'disciplinas' => Disciplina::where('estabelecimento_id', $estabelecimentoId)->where('estado', Estado::ATIVO->value)->orderBy('nome')->get(['id', 'nome']),
            'niveisAcademicos' => NivelAcademico::where('estabelecimento_id', $estabelecimentoId)->where('estado', Estado::ATIVO->value)->orderBy('ordem')->get(['id', 'nome', 'ordem']),
            'anosLectivos' => AnoLectivo::where('estabelecimento_id', $estabelecimentoId)->orderByDesc('data_inicio')->get(['id', 'nome', 'estado']),
        ];
    }
}
