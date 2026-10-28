<?php

namespace Modules\Matricula\Actions;

use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Core\Traits\LogService;

class RegistarHistoricoMatriculaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        Matricula $matricula,
        ?EstadoMatriculaEnum $estadoAnterior,
        EstadoMatriculaEnum $estadoNovo,
        ?int $utilizadorId = null,
        ?string $dataEfeito = null,
        ?string $motivo = null,
        ?array $detalhe = null,
    ): void
    {
        $this->comLog('Registar histórico matrícula', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matricula, $estadoAnterior, $estadoNovo, $utilizadorId, $dataEfeito, $motivo, $detalhe));
    }

    private function executarSemRegisto(
        Matricula $matricula,
        ?EstadoMatriculaEnum $estadoAnterior,
        EstadoMatriculaEnum $estadoNovo,
        ?int $utilizadorId = null,
        ?string $dataEfeito = null,
        ?string $motivo = null,
        ?array $detalhe = null,
    ): void {
        MatriculaHistorico::create([
            'matricula_id' => $matricula->id,
            'estado_anterior' => $estadoAnterior?->value,
            'estado_novo' => $estadoNovo->value,
            'utilizador_id' => $utilizadorId,
            'data_efeito' => $dataEfeito,
            'motivo' => $motivo,
            'detalhe' => $detalhe,
        ]);
    }
}
