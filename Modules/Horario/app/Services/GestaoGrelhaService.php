<?php

namespace Modules\Horario\Services;

use Modules\Horario\Actions\AlterarEntradaAction;
use Modules\Horario\Actions\CriarEntradaAction;
use Modules\Horario\Actions\EliminarEntradaAction;
use Modules\Horario\Actions\EncerrarEntradaAction;
use Modules\Horario\DTO\GrelhaEntradaDTO;
use Modules\Horario\Models\GrelhaEntrada;

/** Escrita da grelha: traduz os dados validados do pedido em DTO e delega nas Actions. */
class GestaoGrelhaService
{
    public function __construct(
        private CriarEntradaAction $criar,
        private AlterarEntradaAction $alterar,
        private EncerrarEntradaAction $encerrar,
        private EliminarEntradaAction $eliminar,
    ) {
    }

    /** @param array<string, mixed> $dados dados validados de `GuardarEntradaRequest` */
    public function criar(array $dados): GrelhaEntrada
    {
        return $this->criar->executar($this->dto($dados, (int) $dados['turma_id']));
    }

    /** @param array<string, mixed> $dados idem; a turma é sempre a da entrada */
    public function alterar(GrelhaEntrada $entrada, array $dados): GrelhaEntrada
    {
        return $this->alterar->executar($entrada, $this->dto($dados, (int) $entrada->turma_id));
    }

    public function encerrar(GrelhaEntrada $entrada, string $dataFim): GrelhaEntrada
    {
        return $this->encerrar->executar($entrada, $dataFim);
    }

    public function eliminar(GrelhaEntrada $entrada): void
    {
        $this->eliminar->executar($entrada);
    }

    private function dto(array $dados, int $turmaId): GrelhaEntradaDTO
    {
        return new GrelhaEntradaDTO(
            turma_id: $turmaId,
            dia_semana: (int) $dados['dia_semana'],
            horario_id: (int) $dados['horario_id'],
            disciplina_id: (int) $dados['disciplina_id'],
            sala_id: isset($dados['sala_id']) ? (int) $dados['sala_id'] : null,
            vigencia_inicio: $dados['vigencia_inicio'],
            vigencia_fim: $dados['vigencia_fim'] ?? null,
        );
    }
}
