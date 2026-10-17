<?php

namespace Modules\Turma\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\NivelAcademico;

class NivelAcademicoSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1;
        $estabelecimentoId = 1;

        // Obter o tenant real e converter para o contexto da aplicação.
        $tenant = Tenant::query()->findOrFail($tenantId);

        $contexto = app(TenantContext::class);

        $contexto->executarComo(
            $tenant->paraTenantAtual(),
            function () use ($tenantId, $estabelecimentoId): void {
                // Confirmar que o estabelecimento pertence ao tenant.
                Estabelecimento::query()
                    ->where('id', $estabelecimentoId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                $niveis = [
                    // Creche
                    [
                        'codigo' => 'CRECHE_I',
                        'nome' => 'Creche I',
                        'ordem' => 1,
                        'etapa_ensino' => EtapaEnsinoEnum::CRECHE,
                    ],
                    [
                        'codigo' => 'CRECHE_II',
                        'nome' => 'Creche II',
                        'ordem' => 2,
                        'etapa_ensino' => EtapaEnsinoEnum::CRECHE,
                    ],

                    // Pré-Escolar
                    [
                        'codigo' => 'PRE_I',
                        'nome' => 'Iniciação',
                        'ordem' => 3,
                        'etapa_ensino' => EtapaEnsinoEnum::PRE_ESCOLAR,
                    ],
                    [
                        'codigo' => 'PRE_II',
                        'nome' => 'Pré-Escolar I',
                        'ordem' => 4,
                        'etapa_ensino' => EtapaEnsinoEnum::PRE_ESCOLAR,
                    ],
                    [
                        'codigo' => 'PRE_III',
                        'nome' => 'Pré-Escolar II',
                        'ordem' => 5,
                        'etapa_ensino' => EtapaEnsinoEnum::PRE_ESCOLAR,
                    ],

                    // Ensino Primário
                    [
                        'codigo' => 'PRI_1',
                        'nome' => '1.ª Classe',
                        'ordem' => 6,
                        'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO,
                    ],
                    [
                        'codigo' => 'PRI_2',
                        'nome' => '2.ª Classe',
                        'ordem' => 7,
                        'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO,
                    ],
                    [
                        'codigo' => 'PRI_3',
                        'nome' => '3.ª Classe',
                        'ordem' => 8,
                        'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO,
                    ],
                    [
                        'codigo' => 'PRI_4',
                        'nome' => '4.ª Classe',
                        'ordem' => 9,
                        'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO,
                    ],
                    [
                        'codigo' => 'PRI_5',
                        'nome' => '5.ª Classe',
                        'ordem' => 10,
                        'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO,
                    ],
                    [
                        'codigo' => 'PRI_6',
                        'nome' => '6.ª Classe',
                        'ordem' => 11,
                        'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO,
                    ],

                    // Ensino Secundário
                    [
                        'codigo' => 'SEC_7',
                        'nome' => '7.ª Classe',
                        'ordem' => 12,
                        'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
                    ],
                    [
                        'codigo' => 'SEC_8',
                        'nome' => '8.ª Classe',
                        'ordem' => 13,
                        'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
                    ],
                    [
                        'codigo' => 'SEC_9',
                        'nome' => '9.ª Classe',
                        'ordem' => 14,
                        'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
                    ],
                    [
                        'codigo' => 'SEC_10',
                        'nome' => '10.ª Classe',
                        'ordem' => 15,
                        'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
                    ],
                    [
                        'codigo' => 'SEC_11',
                        'nome' => '11.ª Classe',
                        'ordem' => 16,
                        'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
                    ],
                    [
                        'codigo' => 'SEC_12',
                        'nome' => '12.ª Classe',
                        'ordem' => 17,
                        'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
                    ],
                    [
                        'codigo' => 'SEC_13',
                        'nome' => '13.ª Classe',
                        'ordem' => 18,
                        'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
                    ],
                ];

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
