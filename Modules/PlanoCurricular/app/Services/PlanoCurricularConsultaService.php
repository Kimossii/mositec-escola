<?php

namespace Modules\PlanoCurricular\Services;

use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class PlanoCurricularConsultaService
{
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
