<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;

class EliminarInscricaoDisciplinaAction
{
    public function executar(InscricaoDisciplina $inscricao): void
    {
        if ($inscricao->estado !== EstadoInscricaoDisciplinaEnum::INSCRITA) {
            throw ValidationException::withMessages([
                'inscricao' => 'Só é possível eliminar uma inscrição ainda Inscrita. Para as restantes, altere o estado para Desistida.',
            ]);
        }

        $inscricao->delete();
    }
}
