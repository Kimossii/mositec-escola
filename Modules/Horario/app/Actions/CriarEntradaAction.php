<?php

namespace Modules\Horario\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Horario\DTO\GrelhaEntradaDTO;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Services\ValidadorGrelhaService;
use Modules\Horario\Support\SobreposicaoDeGrelha;
use Modules\Turma\Models\Turma;

/**
 * Cria uma entrada da grelha. Bloqueia a linha da turma antes de validar, para que duas entradas da mesma turma
 * sejam validadas uma de cada vez; em PostgreSQL as constraints de exclusão cobrem o que escapar a isso.
 */
class CriarEntradaAction
{
    use LogService;

    public function __construct(private ValidadorGrelhaService $validador)
    {
    }

    public function executar(GrelhaEntradaDTO $dto): GrelhaEntrada
    {
        return $this->comLog('Criar entrada da grelha de horários', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(GrelhaEntradaDTO $dto): GrelhaEntrada
    {
        try {
            return DB::transaction(function () use ($dto) {
                Turma::query()->whereKey($dto->turma_id)->lockForUpdate()->first();
                $turma = $this->validador->validar($dto);

                return $this->gravar($turma->ano_lectivo_id, $dto);
            });
        } catch (QueryException $e) {
            throw SobreposicaoDeGrelha::comoErroDeValidacao($e) ?? $e;
        }
    }

    /** Reutilizada por `AlterarEntradaAction` para criar a nova versão. */
    public function gravar(int $anoLectivoId, GrelhaEntradaDTO $dto): GrelhaEntrada
    {
        return GrelhaEntrada::create([
            'ano_lectivo_id' => $anoLectivoId,
            'turma_id' => $dto->turma_id,
            'dia_semana' => $dto->dia_semana,
            'horario_id' => $dto->horario_id,
            'disciplina_id' => $dto->disciplina_id,
            'sala_id' => $dto->sala_id,
            'vigencia_inicio' => $dto->vigencia_inicio,
            'vigencia_fim' => $dto->vigencia_fim,
        ]);
    }
}
