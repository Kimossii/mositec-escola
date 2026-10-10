<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Models\PlanoPropina;

class AtualizarPlanoPropinaAction
{
    public const MENSAGEM_VALOR = 'Este plano já tem propinas geradas: o valor não pode ser alterado na edição do plano. As propinas existentes mantêm o seu valor.';

    public const MENSAGEM_CALENDARIO = 'Este plano já tem propinas geradas: a periodicidade e os meses de início e de fim não podem ser alterados, porque definem os períodos das propinas existentes.';

    public function executar(PlanoPropina $plano, PlanoPropinaDTO $dto): PlanoPropina
    {
        return DB::transaction(function () use ($plano, $dto) {
            // Bloqueia o plano: a geração de propinas (F2) e a alteração de preço (F4) também o bloqueiam.
            $bloqueado = PlanoPropina::query()->whereKey($plano->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueado->propinas()->exists()) {
                $this->recusarAlteracoesQueTocamPropinas($bloqueado, $dto);
            }

            $bloqueado->update([
                'nome' => $dto->nome,
                'descricao' => $dto->descricao,
                'periodicidade' => $dto->periodicidade,
                'intervalo_meses' => $dto->intervalo_meses,
                'valor' => $dto->valor,
                'mes_inicio' => $dto->mes_inicio,
                'mes_fim' => $dto->mes_fim,
            ]);

            $bloqueado->substituirAlvos($dto->alvos);

            return $bloqueado->fresh('alvos');
        });
    }

    /**
     * Um plano com propinas (em qualquer estado, mesmo só canceladas) mantém valor e calendário: o valor
     * só muda pela operação própria de alteração de preço (spec Propinas §14, "única porta de entrada"),
     * e o calendário define a ordem, o início e o fim das propinas já geradas (Q10). Nome, descrição e
     * alvos continuam editáveis: só afectam gerações futuras. A excepção é lançada dentro da transacção
     * e não é apanhada: reverte tudo (mesmo padrão de AtualizarConfiguracaoMonetariaAction).
     */
    private function recusarAlteracoesQueTocamPropinas(PlanoPropina $plano, PlanoPropinaDTO $dto): void
    {
        $erros = [];

        if ($plano->valor->unidadesMenores() !== $dto->valor->unidadesMenores()) {
            $erros['valor'] = self::MENSAGEM_VALOR;
        }

        $calendarioMuda = $plano->periodicidade !== $dto->periodicidade
            || (int) $plano->intervalo_meses !== $dto->intervalo_meses
            || (int) $plano->mes_inicio !== $dto->mes_inicio
            || (int) $plano->mes_fim !== $dto->mes_fim;

        if ($calendarioMuda) {
            $erros['periodicidade'] = self::MENSAGEM_CALENDARIO;
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }
}
