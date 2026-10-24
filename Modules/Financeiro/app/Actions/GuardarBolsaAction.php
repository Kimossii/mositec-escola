<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Enums\EstadoBolsa;
use Modules\Financeiro\Models\Bolsa;
use Modules\Financeiro\Support\Multa;
use Modules\Matricula\Models\Matricula;

/**
 * Cria ou edita uma bolsa. As datas ficam dentro do ano lectivo da bolsa e não pode haver duas bolsas activas do
 * mesmo aluno com datas sobrepostas. Editar só afecta as propinas geradas depois (as que já nasceram não mudam).
 */
class GuardarBolsaAction
{
    /** @param array{matricula_id?: int, nome: string, percentagem: string, data_inicio: string, data_fim: string, motivo: string} $dados */
    public function executar(array $dados, ?Bolsa $bolsa = null): Bolsa
    {
        return DB::transaction(function () use ($dados, $bolsa) {
            if ($bolsa === null) {
                $matricula = Matricula::query()->with('anoLectivo')->findOrFail($dados['matricula_id']);
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

            if ($dados['data_inicio'] < $ano->data_inicio->toDateString() || $dados['data_fim'] > $ano->data_fim->toDateString()) {
                throw ValidationException::withMessages(['data_inicio' => "As datas da bolsa têm de estar dentro do ano lectivo ({$ano->nome})."]);
            }

            $sobreposta = Bolsa::query()
                ->where('aluno_id', $alunoId)->where('ano_lectivo_id', $ano->id)->where('estado', EstadoBolsa::ACTIVA->value)
                ->when($bolsa !== null, fn ($q) => $q->where('id', '!=', $bolsa->id))
                ->where('data_inicio', '<=', $dados['data_fim'])->where('data_fim', '>=', $dados['data_inicio'])
                ->exists();

            if ($sobreposta) {
                throw ValidationException::withMessages(['data_inicio' => 'Este aluno já tem uma bolsa activa nestas datas.']);
            }

            $atributos = [
                'nome' => trim($dados['nome']),
                'percentagem' => Multa::percentagemParaPontosBase($dados['percentagem']),
                'data_inicio' => $dados['data_inicio'],
                'data_fim' => $dados['data_fim'],
                'motivo' => trim($dados['motivo']),
            ];

            if ($bolsa === null) {
                return Bolsa::create($atributos + ['aluno_id' => $alunoId, 'ano_lectivo_id' => $ano->id]);
            }

            $bolsa->update($atributos);

            return $bolsa->fresh();
        });
    }
}
