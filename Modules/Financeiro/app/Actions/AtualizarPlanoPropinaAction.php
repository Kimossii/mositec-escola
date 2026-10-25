<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Core\Traits\LogService;

class AtualizarPlanoPropinaAction
{
    use LogService;

    public const MENSAGEM_VALOR = 'Este plano já tem propinas geradas: o valor só muda em «Alterar preço», onde escolhe como tratar as propinas já geradas.';

    public const MENSAGEM_CALENDARIO = 'Este plano já tem propinas geradas: a periodicidade e as datas de início e de fim não podem ser alteradas, porque definem os períodos das propinas existentes.';

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoPropina $plano, PlanoPropinaDTO $dto): PlanoPropina
    {
        return $this->comLog('Atualizar plano propina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($plano, $dto));
    }

    private function executarSemRegisto(PlanoPropina $plano, PlanoPropinaDTO $dto): PlanoPropina
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
                'data_inicio' => $dto->data_inicio,
                'data_fim' => $dto->data_fim,
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
            || $plano->data_inicio?->toDateString() !== $dto->data_inicio->toDateString()
            || $plano->data_fim?->toDateString() !== $dto->data_fim->toDateString();

        if ($calendarioMuda) {
            $erros['periodicidade'] = self::MENSAGEM_CALENDARIO;
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }
}
