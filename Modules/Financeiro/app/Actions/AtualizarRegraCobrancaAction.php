<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\DTO\RegraCobrancaDTO;
use Modules\Financeiro\Models\RegraCobranca;

class AtualizarRegraCobrancaAction
{
    public function executar(RegraCobrancaDTO $dto): RegraCobranca
    {
        return DB::transaction(function () use ($dto) {
            $regra = RegraCobranca::doTenant();
            // Serializa edições concorrentes da mesma regra (regra + escalões).
            $regra = RegraCobranca::query()->lockForUpdate()->findOrFail($regra->id);

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

            return $regra->fresh();
        });
    }
}
