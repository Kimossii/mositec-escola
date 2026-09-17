<?php

namespace Modules\Turma\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Models\Horario;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\Turno;

class TurnoConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return Turno::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->with(['turnoHorarios.horario'])
            ->when(($filtros['estado'] ?? '') !== '', fn ($query) => $query->where('estado', $filtros['estado']))
            ->when($filtros['pesquisa'] ?? null, fn ($query, $pesquisa) => $query->where('nome', 'like', "%{$pesquisa}%"))
            ->orderBy('nome')
            ->paginate($porPagina)
            ->withQueryString();
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
