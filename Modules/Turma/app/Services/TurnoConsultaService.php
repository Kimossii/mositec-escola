<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Models\Horario;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\Turno;

class TurnoConsultaService
{
    public function listar(): Collection
    {
        return Turno::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->with(['turnoHorarios.horario'])
            ->orderBy('nome')
            ->get();
    }

    public function horariosDisponiveis(): Collection
    {
        return Horario::orderBy('hora_inicio')->get(['id', 'nome', 'hora_inicio', 'hora_fim']);
    }

    public function comRelacoes(Turno $turno): Turno
    {
        return $turno->load([
            'turnoHorarios' => fn ($query) => $query->with('horario')->orderBy('ordem'),
            'turmas' => fn ($query) => $query->with(['anoLectivo:id,nome', 'nivelAcademico:id,nome'])->orderBy('nome'),
        ]);
    }
}
