<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Financeiro\DTO\RegraCobrancaDTO;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\AplicarMultasDiariasService;
use Modules\Core\Traits\LogService;

class AtualizarRegraCobrancaAction
{
    use LogService;

    public function __construct(
        private AplicarMultasDiariasService $multasDoDia,
        private RegistarAuditoriaTenantAction $auditoria,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(RegraCobrancaDTO $dto): RegraCobranca
    {
        return $this->comLog('Atualizar regra cobrança', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(RegraCobrancaDTO $dto): RegraCobranca
    {
        $regra = DB::transaction(function () use ($dto) {
            $regra = RegraCobranca::doTenant();
            // Serializa edições concorrentes da mesma regra (regra + escalões).
            $regra = RegraCobranca::query()->lockForUpdate()->findOrFail($regra->id);

            $antes = $this->instantaneo($regra);

            $regra->fill([
                'dia_vencimento' => $dto->dia_vencimento,
                'dias_tolerancia' => $dto->dias_tolerancia,
                'permite_pagamento_parcial' => $dto->permite_pagamento_parcial,
                'permite_pagamento_antecipado' => $dto->permite_pagamento_antecipado,
                'gerar_automaticamente' => $dto->gerar_automaticamente,
                'permite_negociacao' => $dto->permite_negociacao,
                'desconto_maximo_negociacao' => $dto->desconto_maximo_negociacao,
            ]);
            if ($dto->multa_activa !== null) {
                $regra->multa_activa = $dto->multa_activa;
            }
            foreach (['exigir_regularizacao_servicos', 'gerar_obrigatorios_ao_matricular'] as $campo) {
                if ($dto->{$campo} !== null) {
                    $regra->{$campo} = $dto->{$campo};
                }
            }
            if ($dto->exigir_regularizacao_matricula !== null) {
                $regra->exigir_regularizacao_matricula = $dto->exigir_regularizacao_matricula;
            }
            if ($dto->exigir_regularizacao_trancamento !== null) {
                $regra->exigir_regularizacao_trancamento = $dto->exigir_regularizacao_trancamento;
            }
            $regra->save();

            // Regra e escalões num só passo: qualquer falha reverte tudo (sem apanhar excepções aqui).
            if ($dto->escaloes !== null) {
                $regra->escaloes()->delete();
            }
            foreach ($dto->escaloes ?? [] as $escalao) {
                $regra->escaloes()->create([
                    'ordem' => $escalao->ordem,
                    'dias_atraso' => $escalao->dias_atraso,
                    'tipo' => $escalao->tipo,
                    'valor' => $escalao->valor,
                ]);
            }

            $regra = $regra->fresh();

            // Auditoria do tenant: diff de todos os campos alterados (incluindo escalões), na mesma transacção.
            $this->auditoria->executar('regra_cobranca.actualizada', 'regras_cobranca', (int) $regra->id, $antes, $this->instantaneo($regra));

            return $regra;
        });

        // Regras novas (ligar multas, escalões): o varrimento de hoje volta a poder correr na próxima visita.
        $this->multasDoDia->esquecerDoDia();

        return $regra;
    }

    /** @return array<string, mixed> campos da regra + escalões, para o diff da auditoria */
    private function instantaneo(RegraCobranca $regra): array
    {
        return $regra->only($regra->getFillable()) + [
            'escaloes' => $regra->escaloes()->get(['ordem', 'dias_atraso', 'tipo', 'valor'])->map->only(['ordem', 'dias_atraso', 'tipo', 'valor'])->all(),
        ];
    }
}
