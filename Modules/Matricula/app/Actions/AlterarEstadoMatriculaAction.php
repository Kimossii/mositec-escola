<?php

namespace Modules\Matricula\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;

class AlterarEstadoMatriculaAction
{
    public function __construct(
        private RegistarHistoricoMatriculaAction $registarHistorico,
        private RelogioDoTenant $relogio,
    ) {
    }

    /**
     * Estado, data de fim, histórico e reacções síncronas de outros módulos (ex.: cancelar propinas futuras)
     * numa só transacção: ou muda tudo ou nada (Q5 do plano de fases do Financeiro).
     */
    public function executar(
        Matricula $matricula,
        EstadoMatriculaEnum $novoEstado,
        ?int $utilizadorId = null,
        ?string $dataFim = null,
    ): Matricula {
        return DB::transaction(function () use ($matricula, $novoEstado, $utilizadorId, $dataFim) {
            // Relida e bloqueada: duas mudanças concorrentes fazem fila aqui e a segunda decide sobre o estado
            // já gravado pela primeira (um model desactualizado nunca passa a validação).
            $bloqueada = Matricula::query()->whereKey($matricula->getKey())->lockForUpdate()->firstOrFail();
            $estadoActual = $bloqueada->estado;

            if (! $estadoActual->podeTransitarPara($novoEstado)) {
                throw ValidationException::withMessages([
                    'estado' => "Não é possível alterar o estado de {$estadoActual->label()} para {$novoEstado->label()}.",
                ]);
            }

            $bloqueada->estado = $novoEstado;
            $bloqueada->editado_por = $utilizadorId;

            if ($novoEstado->eTerminal()) {
                $bloqueada->data_fim = $this->dataFimValidada($bloqueada, $novoEstado, $dataFim);
            }

            $bloqueada->save();

            $this->registarHistorico->executar($bloqueada, $estadoActual, $novoEstado, $utilizadorId);

            MatriculaEstadoAlterado::dispatch(
                (int) $bloqueada->id,
                $estadoActual->value,
                $novoEstado->value,
                $novoEstado->eTerminal() ? $bloqueada->data_fim->toDateString() : $this->relogio->hoje()->toDateString(),
                $utilizadorId,
            );

            return $bloqueada->fresh();
        });
    }

    /**
     * O1 (aprovado pelo dono em F3): a data de fim (omissão: hoje da escola) fica entre a data da matrícula e hoje.
     * Uma data retroactiva anterior à matrícula cancelaria em silêncio propinas de períodos já vencidos; uma futura
     * terminaria a matrícula antes do tempo. Concluída aceita uma data futura até ao fim do ano lectivo. Se a
     * matrícula ainda não começou, o mínimo é hoje (o intervalo [data da matrícula, hoje] estaria vazio).
     */
    private function dataFimValidada(Matricula $matricula, EstadoMatriculaEnum $novoEstado, ?string $pedida): string
    {
        $hoje = $this->relogio->hoje()->toDateString();
        $data = $pedida === null ? $hoje : CarbonImmutable::parse($pedida)->toDateString();
        $inicio = $matricula->data_matricula?->toDateString() ?? $hoje;
        $minimo = min($inicio, $hoje);
        $fimDoAno = $novoEstado === EstadoMatriculaEnum::CONCLUIDA ? $matricula->anoLectivo?->data_fim?->toDateString() : null;
        $maximo = max($hoje, $fimDoAno ?? $hoje);

        if ($data < $minimo) {
            throw ValidationException::withMessages(['data_fim' => $minimo === $inicio
                ? 'A data de fim não pode ser anterior à data da matrícula (' . $this->formatar($inicio) . ').'
                : 'A data de fim não pode ser anterior a hoje (' . $this->formatar($hoje) . ').']);
        }

        if ($data > $maximo) {
            throw ValidationException::withMessages(['data_fim' => $maximo === $hoje
                ? 'A data de fim não pode ser posterior a hoje (' . $this->formatar($hoje) . ').'
                : 'A data de fim de uma matrícula concluída não pode ser posterior ao fim do ano lectivo (' . $this->formatar($maximo) . ').']);
        }

        return $data;
    }

    private function formatar(string $data): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $data)->format('d/m/Y');
    }
}
