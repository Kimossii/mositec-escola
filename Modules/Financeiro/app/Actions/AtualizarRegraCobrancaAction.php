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

            $regra->fill([
                'dia_vencimento' => $dto->dia_vencimento,
                'dias_tolerancia' => $dto->dias_tolerancia,
                'permite_pagamento_parcial' => $dto->permite_pagamento_parcial,
                'permite_pagamento_antecipado' => $dto->permite_pagamento_antecipado,
                'gerar_automaticamente' => $dto->gerar_automaticamente,
                'permite_negociacao' => $dto->permite_negociacao,
                'desconto_maximo_negociacao' => $dto->desconto_maximo_negociacao,
            ])->save();

            return $regra->fresh();
        });
    }
}
