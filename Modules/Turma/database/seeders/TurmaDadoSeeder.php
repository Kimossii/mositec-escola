<?php

namespace Modules\Turma\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

class TurmaDadoSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 1;
        $estabelecimentoId = 1;
        $anoLectivoNome = '2026/2027';

        $tenant = Tenant::query()->findOrFail($tenantId);

        app(TenantContext::class)->executarComo(
            $tenant->paraTenantAtual(),
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

                // Obter o ano lectivo.
                $anoLectivo = AnoLectivo::query()
                    ->where('tenant_id', $tenantId)
                    ->where('estabelecimento_id', $estabelecimentoId)
                    ->where('nome', $anoLectivoNome)
                    ->firstOrFail();

                // Confirmar que os níveis académicos existem.
                $codigosNiveis = [
                    'PRI_1',
                    'PRI_2',
                    'PRI_3',
                    'PRI_4',
                    'PRI_5',
                    'PRI_6',
                ];

                $niveis = NivelAcademico::query()
                    ->where('estabelecimento_id', $estabelecimentoId)
                    ->whereIn('codigo', $codigosNiveis)
                    ->get()
                    ->keyBy('codigo');

                foreach ($codigosNiveis as $codigo) {
                    if (! $niveis->has($codigo)) {
                        throw new \RuntimeException(
                            "O nível académico {$codigo} não existe no estabelecimento {$estabelecimentoId}."
                        );
                    }
                }

                /*
                 * Os turnos não têm a coluna "codigo".
                 * A identificação é feita pelo nome e estabelecimento.
                 */
                $turnos = Turno::query()
                    ->where('estabelecimento_id', $estabelecimentoId)
                    ->whereIn('nome', ['Manhã', 'Tarde'])
                    ->get()
                    ->keyBy('nome');

                // Garantir que os dois turnos existem.
                foreach (['Manhã', 'Tarde'] as $nomeTurno) {
                    if (! $turnos->has($nomeTurno)) {
                        throw new \RuntimeException(
                            "O turno {$nomeTurno} não existe no estabelecimento {$estabelecimentoId}."
                        );
                    }
                }

                $turmas = [];

                foreach (range(1, 6) as $classe) {
                    $codigoNivel = 'PRI_'.$classe;

                    foreach ([
                        [
                            'sufixo' => 'M',
                            'nomeTurno' => 'Manhã',
                        ],
                        [
                            'sufixo' => 'T',
                            'nomeTurno' => 'Tarde',
                        ],
                    ] as $configuracao) {
                        $codigoTurma = sprintf(
                            '%s-%s',
                            $classe,
                            $configuracao['sufixo']
                        );

                        $turmas[] = [
                            'ano_lectivo_id' => $anoLectivo->id,
                            'nivel_academico_id' => $niveis[$codigoNivel]->id,
                            'codigo' => $codigoTurma,
                            'nome' => sprintf(
                                '%s.ª Classe - %s',
                                $classe,
                                $configuracao['nomeTurno']
                            ),
                            'turno_id' => $turnos
                                ->get($configuracao['nomeTurno'])
                                ->id,
                        ];
                    }
                }

                DB::transaction(function () use (
                    $turmas,
                    $tenantId
                ): void {
                    foreach ($turmas as $dados) {
                        Turma::query()->updateOrCreate(
                            [
                                'ano_lectivo_id' => $dados['ano_lectivo_id'],
                                'codigo' => $dados['codigo'],
                            ],
                            [
                                'tenant_id' => $tenantId,
                                'nivel_academico_id' => $dados['nivel_academico_id'],
                                'codigo' => $dados['codigo'],
                                'nome' => $dados['nome'],
                                'turno_id' => $dados['turno_id'],
                                'estado' => 1,
                                'estado_descricao' => 'Ativo',
                            ]
                        );
                    }
                });

                $this->command?->info(
                    sprintf(
                        '%d turmas criadas ou actualizadas com sucesso.',
                        count($turmas)
                    )
                );
            }
        );
    }
}
