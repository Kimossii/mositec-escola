<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;
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
        $estabelecimentoId = Estabelecimento::current()?->id;

        return [
            'anoLectivos' => AnoLectivo::where('estabelecimento_id', $estabelecimentoId)->orderByDesc('nome')->get(['id', 'nome']),
            'niveisAcademicos' => NivelAcademico::where('estabelecimento_id', $estabelecimentoId)->orderBy('ordem')->get(['id', 'nome']),
            'turnos' => Turno::where('estabelecimento_id', $estabelecimentoId)->orderBy('nome')->get(['id', 'nome']),
        ];
    }

    public function salasDisponiveis(): Collection
    {
        return Sala::orderBy('codigo')->get(['id', 'codigo', 'nome']);
    }
}
