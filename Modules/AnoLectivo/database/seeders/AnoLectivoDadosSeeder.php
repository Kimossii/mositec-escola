<?php

namespace Modules\AnoLectivo\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Tenant\Models\Tenant;

class AnoLectivoDadosSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1;
        $estabelecimentoId = 1;

        // Obter o tenant real e estabelecer o contexto da aplicação.
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

                /*
                 * Dados iniciais.
                 *
                 * O ano lectivo de 2026/2027 fica activo.
                 * Ajusta as datas e o estado conforme a realidade
                 * do estabelecimento.
                 */
                $anosLectivos = [
                    [
                        'nome' => '2026/2027',
                        'data_inicio' => '2026-03-01',
                        'data_fim' => '2027-08-31',
                        'estado' => EstadoAnoLectivo::ATIVO,
                    ],
                ];

                DB::transaction(function () use (
                    $anosLectivos,
                    $tenantId,
                    $estabelecimentoId
                ): void {
                    foreach ($anosLectivos as $dados) {
                        $anoLectivo = AnoLectivo::withTrashed()
                            ->where('estabelecimento_id', $estabelecimentoId)
                            ->where('nome', $dados['nome'])
                            ->first();

                        if ($anoLectivo?->trashed()) {
                            throw new \RuntimeException(
                                "O ano lectivo {$dados['nome']} existe, mas foi eliminado. "
                                . 'É necessário verificar o registo antes de continuar.'
                            );
                        }

                        if (
                            $dados['estado'] === EstadoAnoLectivo::ATIVO
                            && AnoLectivo::query()
                                ->where('estabelecimento_id', $estabelecimentoId)
                                ->where('estado', EstadoAnoLectivo::ATIVO->value)
                                ->where('nome', '!=', $dados['nome'])
                                ->exists()
                        ) {
                            throw new \RuntimeException(
                                'Já existe outro ano lectivo activo neste estabelecimento. '
                                . 'O seeder não vai alterar automaticamente o ano lectivo activo.'
                            );
                        }

                        AnoLectivo::query()->updateOrCreate(
                            [
                                'estabelecimento_id' => $estabelecimentoId,
                                'nome' => $dados['nome'],
                            ],
                            [
                                'tenant_id' => $tenantId,
                                'data_inicio' => $dados['data_inicio'],
                                'data_fim' => $dados['data_fim'],
                                'estado' => $dados['estado']->value,
                                'estado_descricao' => $dados['estado']->label(),
                            ]
                        );
                    }
                });

                $this->command?->info(
                    'Anos lectivos criados ou actualizados com sucesso.'
                );
            }
        );
    }
}
