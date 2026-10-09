<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Financeiro\DTO\CopiarPlanosPropinaDTO;
use Modules\Financeiro\DTO\ResumoCopiaPlanosDTO;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\CalendarioDePlano;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turno;

/**
 * Copia os planos activos de um ano lectivo para outro. Os planos copiados ficam inactivos
 * (a escola revê e activa). O que não pode ser copiado é ignorado com motivo, sem falhar o todo.
 */
class CopiarPlanosPropinaAction
{
    public function __construct(private PlanoPropinaConsultaService $consulta)
    {
    }

    public function executar(CopiarPlanosPropinaDTO $dto): ResumoCopiaPlanosDTO
    {
        $destino = AnoLectivo::findOrFail($dto->ano_destino_id);
        $resumo = new ResumoCopiaPlanosDTO();

        DB::transaction(function () use ($dto, $destino, $resumo) {
            $planos = PlanoPropina::query()
                ->where('ano_lectivo_id', $dto->ano_origem_id)
                ->with('alvos')
                ->orderBy('id')
                ->get();

            foreach ($planos as $plano) {
                $motivo = $this->motivoParaIgnorar($plano, $destino);

                if ($motivo !== null) {
                    $resumo->ignorou($plano->nome, $motivo);

                    continue;
                }

                $copia = PlanoPropina::create([
                    'ano_lectivo_id' => $destino->id,
                    'nome' => $plano->nome,
                    'descricao' => $plano->descricao,
                    'periodicidade' => $plano->periodicidade,
                    'intervalo_meses' => $plano->intervalo_meses,
                    'valor' => $plano->valor,
                    'mes_inicio' => $plano->mes_inicio,
                    'mes_fim' => $plano->mes_fim,
                    'estado' => Estado::INATIVO->value,
                ]);

                $copia->substituirAlvos($this->alvosDe($plano));

                $resumo->copiou($copia->nome, $copia->id);
            }
        });

        return $resumo;
    }

    private function motivoParaIgnorar(PlanoPropina $plano, AnoLectivo $destino): ?string
    {
        if ($plano->estado !== Estado::ATIVO->value) {
            return 'Plano inactivo na origem.';
        }

        $alvos = $this->alvosDe($plano);

        if (AlvosDoPlano::idsDeTurma($alvos) !== []) {
            return 'Aplica-se a uma turma específica, e as turmas pertencem ao ano lectivo de origem: crie o plano manualmente no destino.';
        }

        if ($this->temAlvoEliminado($alvos)) {
            return 'Um dos alvos (nível, curso ou turno) foi eliminado.';
        }

        if (PlanoPropina::query()->where('ano_lectivo_id', $destino->id)->where('nome', $plano->nome)->exists()) {
            return 'Já existe um plano com este nome no ano lectivo de destino.';
        }

        $competencias = CalendarioDePlano::competencias($plano->mes_inicio, $plano->mes_fim, $destino->data_inicio);

        if ($this->consulta->colisaoDeAlvos($destino->id, $alvos, $competencias, null)) {
            return 'Já existe outro plano no ano lectivo de destino, com período sobreposto, aplicável aos mesmos alvos.';
        }

        return null;
    }

    /**
     * @return list<array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}>
     */
    private function alvosDe(PlanoPropina $plano): array
    {
        return AlvosDoPlano::paraGravar($plano->alvos->map(fn (PlanoPropinaAlvo $alvo) => $alvo->only([
            'nivel_academico_id', 'curso_id', 'turno_id', 'turma_id',
        ]))->all());
    }

    /**
     * @param  list<array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}>  $alvos
     */
    private function temAlvoEliminado(array $alvos): bool
    {
        $existem = fn (string $modelo, string $campo) => $this->todosExistem($modelo, array_filter(array_column($alvos, $campo)));

        return ! ($existem(NivelAcademico::class, 'nivel_academico_id')
            && $existem(Curso::class, 'curso_id')
            && $existem(Turno::class, 'turno_id'));
    }

    /**
     * Os modelos usam soft delete (excluído por omissão) e o scope de tenant.
     *
     * @param  class-string  $modelo
     * @param  array<int, int>  $ids
     */
    private function todosExistem(string $modelo, array $ids): bool
    {
        $ids = array_unique($ids);

        return $ids === [] || $modelo::query()->whereIn('id', $ids)->count() === count($ids);
    }
}
