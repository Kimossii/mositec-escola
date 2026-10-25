<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Financeiro\DTO\CopiarPlanosPropinaDTO;
use Modules\Financeiro\DTO\ResumoCopiaPlanosDTO;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\CalendarioDePlano;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turno;
use Modules\Core\Traits\LogService;

/**
 * Copia os planos activos de um ano lectivo para outro. Os planos copiados ficam inactivos
 * (a escola revê e activa). O que não pode ser copiado é ignorado com motivo, sem falhar o todo.
 */
class CopiarPlanosPropinaAction
{
    use LogService;

    public function __construct(private PlanoPropinaConsultaService $consulta)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(CopiarPlanosPropinaDTO $dto): ResumoCopiaPlanosDTO
    {
        return $this->comLog('Copiar planos propina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(CopiarPlanosPropinaDTO $dto): ResumoCopiaPlanosDTO
    {
        $origem = AnoLectivo::findOrFail($dto->ano_origem_id);
        $destino = AnoLectivo::findOrFail($dto->ano_destino_id);
        // Desloca as datas pela diferença entre o início dos dois anos lectivos (opção mais simples:
        // um deslocamento em meses inteiros — nunca em dias: os anos bissextos deslocariam o fim do plano para o
        // mês seguinte e o sinal ficaria trocado).
        $deslocamento = ($destino->data_inicio->year * 12 + $destino->data_inicio->month)
            - ($origem->data_inicio->year * 12 + $origem->data_inicio->month);
        $resumo = new ResumoCopiaPlanosDTO();

        DB::transaction(function () use ($dto, $destino, $deslocamento, $resumo) {
            $planos = PlanoPropina::query()
                ->where('ano_lectivo_id', $dto->ano_origem_id)
                ->with('alvos')
                ->orderBy('id')
                ->get();

            foreach ($planos as $plano) {
                $motivo = $this->motivoParaIgnorar($plano, $destino, $deslocamento);

                if ($motivo !== null) {
                    $resumo->ignorou($plano->nome, $motivo);

                    continue;
                }

                $copia = PlanoPropina::create([
                    'ano_lectivo_id' => $destino->id,
                    'nome' => $plano->nome,
                    'descricao' => $plano->descricao,
                    // Propina sempre mensal: a cópia normaliza a periodicidade e o intervalo para 1 mês.
                    'periodicidade' => Periodicidade::MENSAL,
                    'intervalo_meses' => 1,
                    'valor' => $plano->valor,
                    'data_inicio' => $plano->data_inicio->addMonthsNoOverflow($deslocamento),
                    'data_fim' => $plano->data_fim->addMonthsNoOverflow($deslocamento),
                    'estado' => Estado::INATIVO->value,
                ]);

                $copia->substituirAlvos($this->alvosDe($plano));

                $resumo->copiou($copia->nome, $copia->id);
            }
        });

        return $resumo;
    }

    private function motivoParaIgnorar(PlanoPropina $plano, AnoLectivo $destino, int $deslocamento): ?string
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

        $competencias = CalendarioDePlano::competencias(
            $plano->data_inicio->addMonthsNoOverflow($deslocamento),
            $plano->data_fim->addMonthsNoOverflow($deslocamento),
        );

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
