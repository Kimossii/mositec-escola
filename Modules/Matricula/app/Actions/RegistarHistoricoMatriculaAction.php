<?php

namespace Modules\Matricula\Actions;

use Modules\Matricula\DTO\HistoricoMatriculaDTO;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Core\Traits\LogService;

class RegistarHistoricoMatriculaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Matricula $matricula, HistoricoMatriculaDTO $dto): void
    {
        $this->comLog('Registar histórico matrícula', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matricula, $dto));
    }

    private function executarSemRegisto(Matricula $matricula, HistoricoMatriculaDTO $dto): void
    {
        MatriculaHistorico::create([
            'matricula_id' => $matricula->id,
            'estado_anterior' => $dto->estadoAnterior?->value,
            'estado_novo' => $dto->estadoNovo->value,
            'utilizador_id' => $dto->utilizadorId,
            'data_efeito' => $dto->dataEfeito,
            'motivo' => $dto->motivo,
            'detalhe' => $dto->detalhe,
        ]);
    }
}
