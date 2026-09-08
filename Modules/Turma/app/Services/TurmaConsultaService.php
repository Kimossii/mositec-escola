<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

class TurmaConsultaService
{
    public function listar(): Collection
    {
        return Turma::orderBy('codigo')->get();
    }

    public function opcoesFormulario(): array
    {
        return [
            'anoLectivos' => AnoLectivo::orderByDesc('nome')->get(['id', 'nome']),
            'niveisAcademicos' => NivelAcademico::orderBy('ordem')->get(['id', 'nome']),
            'turnos' => Turno::orderBy('nome')->get(['id', 'nome']),
        ];
    }

    public function salasDisponiveis(): Collection
    {
        return Sala::orderBy('codigo')->get(['id', 'codigo', 'nome']);
    }
}
