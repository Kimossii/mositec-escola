<?php

namespace Modules\Turma\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Support\NiveisAcademicosPadrao;

class NivelAcademicoSeeder extends Seeder
{
    public function run(): void
    {
        // O seed corre dentro do contexto de cada escola: usa o tenant e o estabelecimento correntes (nunca o id 1 fixo).
        $tenantId = app(TenantContext::class)->id();
        $estabelecimentoId = (int) Estabelecimento::query()->value('id');

        $contexto = app(TenantContext::class);

        $contexto->executarComo(
            app(TenantContext::class)->atual(),
            function () use ($tenantId, $estabelecimentoId): void {
                // Confirmar que o estabelecimento pertence ao tenant.
                Estabelecimento::query()
                    ->where('id', $estabelecimentoId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                $niveis = NiveisAcademicosPadrao::todos();

                DB::transaction(function () use (
                    $niveis,
                    $tenantId,
                    $estabelecimentoId
                ): void {
                    foreach ($niveis as $nivel) {
                        NivelAcademico::query()->updateOrCreate(
                            [
                                'estabelecimento_id' => $estabelecimentoId,
                                'codigo' => $nivel['codigo'],
                            ],
                            [
                                'tenant_id' => $tenantId,
                                'nome' => $nivel['nome'],
                                'ordem' => $nivel['ordem'],
                                'etapa_ensino' => $nivel['etapa_ensino']->value,
                                'estado' => 1,
                                'estado_descricao' => 'Ativo',
                            ]
                        );
                    }
                });

                $this->command?->info(
                    'Níveis académicos criados ou actualizados com sucesso.'
                );
            }
        );
    }
}
