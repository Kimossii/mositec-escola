<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\GuardarBolsaDTO;
use Modules\Financeiro\Enums\EstadoBolsa;
use Modules\Financeiro\Models\Bolsa;
use Modules\Financeiro\Support\Multa;
use Modules\Matricula\Models\Matricula;
use Modules\Core\Traits\LogService;

/**
 * Cria ou edita uma bolsa. As datas ficam dentro do ano lectivo da bolsa e não pode haver duas bolsas activas do
 * mesmo aluno com datas sobrepostas. Editar só afecta as propinas geradas depois (as que já nasceram não mudam).
 */
class GuardarBolsaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(GuardarBolsaDTO $dto, ?Bolsa $bolsa = null): Bolsa
    {
        return $this->comLog('Guardar bolsa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto, $bolsa));
    }

    private function executarSemRegisto(GuardarBolsaDTO $dto, ?Bolsa $bolsa = null): Bolsa
    {
        return DB::transaction(function () use ($dto, $bolsa) {
            if ($bolsa === null) {
                $matricula = Matricula::query()->with('anoLectivo')->findOrFail((int) $dto->matriculaId);
                $alunoId = (int) $matricula->aluno_id;
                $ano = $matricula->anoLectivo;
            } else {
                $bolsa = Bolsa::query()->whereKey($bolsa->id)->lockForUpdate()->firstOrFail();

                if ($bolsa->estado !== EstadoBolsa::ACTIVA) {
                    throw ValidationException::withMessages(['bolsa' => 'Uma bolsa terminada não se edita.']);
                }

                $alunoId = (int) $bolsa->aluno_id;
                $ano = $bolsa->anoLectivo;
            }

            if ($dto->dataInicio < $ano->data_inicio->toDateString() || $dto->dataFim > $ano->data_fim->toDateString()) {
                throw ValidationException::withMessages(['data_inicio' => "As datas da bolsa têm de estar dentro do ano lectivo ({$ano->nome})."]);
            }

            $sobreposta = Bolsa::query()
                ->where('aluno_id', $alunoId)->where('ano_lectivo_id', $ano->id)->where('estado', EstadoBolsa::ACTIVA->value)
                ->when($bolsa !== null, fn ($q) => $q->where('id', '!=', $bolsa->id))
                ->where('data_inicio', '<=', $dto->dataFim)->where('data_fim', '>=', $dto->dataInicio)
                ->exists();

            if ($sobreposta) {
                throw ValidationException::withMessages(['data_inicio' => 'Este aluno já tem uma bolsa activa nestas datas.']);
            }

            $atributos = [
                'nome' => trim($dto->nome),
                'percentagem' => Multa::percentagemParaPontosBase($dto->percentagem),
                'data_inicio' => $dto->dataInicio,
                'data_fim' => $dto->dataFim,
                'motivo' => trim($dto->motivo),
            ];

            if ($bolsa === null) {
                return Bolsa::create($atributos + ['aluno_id' => $alunoId, 'ano_lectivo_id' => $ano->id]);
            }

            $bolsa->update($atributos);

            return $bolsa->fresh();
        });
    }
}
