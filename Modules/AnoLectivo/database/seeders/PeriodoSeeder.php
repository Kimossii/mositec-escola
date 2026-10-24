<?php

namespace Modules\AnoLectivo\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;

class PeriodoSeeder extends Seeder
{
    public function run(): void
    {
        // O seed corre dentro do contexto de cada escola: usa o tenant e o estabelecimento correntes (nunca o id 1 fixo).
        $tenantId = app(TenantContext::class)->id();
        $estabelecimentoId = (int) Estabelecimento::query()->value('id');
        $anoLectivoNome = '2026/2027';

        app(TenantContext::class)->executarComo(
            app(TenantContext::class)->atual(),
            function () use (
                $tenantId,
                $estabelecimentoId,
                $anoLectivoNome
            ): void {
                // Confirmar que o estabelecimento pertence ao tenant.
                Estabelecimento::query()
                    ->where('id', $estabelecimentoId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                // Obter o ano lectivo do estabelecimento.
                $anoLectivo = AnoLectivo::query()
                    ->where('tenant_id', $tenantId)
                    ->where('estabelecimento_id', $estabelecimentoId)
                    ->where('nome', $anoLectivoNome)
                    ->firstOrFail();

                $periodos = [
                    [
                        'nome' => '1.º Trimestre',
                        'tipo' => TipoPeriodo::TRIMESTRE,
                        'numero' => 1,
                        'data_inicio' => '2026-03-01',
                        'data_fim' => '2026-06-30',
                    ],
                    [
                        'nome' => '2.º Trimestre',
                        'tipo' => TipoPeriodo::TRIMESTRE,
                        'numero' => 2,
                        'data_inicio' => '2026-08-01',
                        'data_fim' => '2026-11-30',
                    ],
                    [
                        'nome' => '3.º Trimestre',
                        'tipo' => TipoPeriodo::TRIMESTRE,
                        'numero' => 3,
                        'data_inicio' => '2027-01-05',
                        'data_fim' => '2027-04-30',
                    ],
                ];

                DB::transaction(function () use (
                    $periodos,
                    $anoLectivo
                ): void {
                    foreach ($periodos as $dados) {
                        $inicio = $dados['data_inicio'];
                        $fim = $dados['data_fim'];

                        // Garantir que o período está dentro do ano lectivo.
                        if (
                            $inicio < $anoLectivo->data_inicio->format('Y-m-d')
                            || $fim > $anoLectivo->data_fim->format('Y-m-d')
                            || $inicio > $fim
                        ) {
                            throw new \RuntimeException(
                                "O período {$dados['nome']} está fora do intervalo do ano lectivo."
                            );
                        }

                        // Impedir sobreposições com outros períodos.
                        $sobreposicao = Periodo::query()
                            ->where('ano_lectivo_id', $anoLectivo->id)
                            ->where('data_inicio', '<=', $fim)
                            ->where('data_fim', '>=', $inicio)
                            ->where('numero', '!=', $dados['numero'])
                            ->exists();

                        if ($sobreposicao) {
                            throw new \RuntimeException(
                                "O período {$dados['nome']} sobrepõe-se a outro período existente."
                            );
                        }

                        Periodo::query()->updateOrCreate(
                            [
                                'ano_lectivo_id' => $anoLectivo->id,
                                'numero' => $dados['numero'],
                            ],
                            [
                                'tenant_id' => $anoLectivo->tenant_id,
                                'nome' => $dados['nome'],
                                'tipo' => $dados['tipo']->value,
                                'tipo_descricao' => $dados['tipo']->label(),
                                'data_inicio' => $inicio,
                                'data_fim' => $fim,
                                'estado' => 1,
                                'estado_descricao' => 'Ativo',
                            ]
                        );
                    }
                });

                $this->command?->info(
                    'Períodos lectivos criados ou actualizados com sucesso.'
                );
            }
        );
    }
}
