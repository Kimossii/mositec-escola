<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;

class AlterarEstadoInscricaoDisciplinaAction
{
    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    public function executar(
        InscricaoDisciplina $inscricao,
        EstadoInscricaoDisciplinaEnum $novoEstado,
        ?int $utilizadorId = null,
    ): InscricaoDisciplina {
        $estadoActual = $inscricao->estado;

        if (! $estadoActual->podeTransitarPara($novoEstado)) {
            throw ValidationException::withMessages([
                'estado' => "Não é possível alterar o estado de {$estadoActual->label()} para {$novoEstado->label()}.",
            ]);
        }

        $inscricao->estado = $novoEstado;
        $inscricao->editado_por = $utilizadorId;

        if ($novoEstado->eTerminal()) {
            $inscricao->data_conclusao = $this->relogio->hoje()->toDateString();
        }

        $inscricao->save();

        return $inscricao->fresh();
    }
}
