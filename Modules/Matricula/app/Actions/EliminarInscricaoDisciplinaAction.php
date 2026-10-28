<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Core\Traits\LogService;

class EliminarInscricaoDisciplinaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(InscricaoDisciplina $inscricao): void
    {
        $this->comLog('Eliminar inscrição disciplina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($inscricao));
    }

    private function executarSemRegisto(InscricaoDisciplina $inscricao): void
    {
        if ($inscricao->estado !== EstadoInscricaoDisciplinaEnum::INSCRITA) {
            throw ValidationException::withMessages([
                'inscricao' => 'Só é possível eliminar uma inscrição ainda Inscrita. Para as restantes, altere o estado para Desistida.',
            ]);
        }

        $inscricao->delete();
    }
}
