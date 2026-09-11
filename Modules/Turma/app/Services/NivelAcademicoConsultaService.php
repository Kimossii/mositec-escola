<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class NivelAcademicoConsultaService
{
    public function listar(): Collection
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('ordem')
            ->get();
    }

    public function etapasEnsino(?int $estabelecimentoId = null): SupportCollection
    {
        return NivelAcademico::where('estabelecimento_id', $estabelecimentoId ?? Estabelecimento::current()?->id)
            ->get()
            ->pluck('etapa_ensino')
            ->unique()
            ->values();
    }
}
