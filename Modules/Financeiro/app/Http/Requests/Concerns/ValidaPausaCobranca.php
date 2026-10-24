<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Models\PausaCobranca;

/**
 * Regras e validação cruzada partilhadas por Criar e Atualizar uma pausa de cobrança.
 */
trait ValidaPausaCobranca
{
    /**
     * @return array<string, mixed>
     */
    protected function regrasComuns(): array
    {
        return [
            'descricao' => ['required', 'string', 'max:200'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'cobra_propina' => ['required', 'boolean'],
            'meses' => ['sometimes', 'array'],
            'meses.*.ano' => ['required', 'integer'],
            'meses.*.mes' => ['required', 'integer', 'min:1', 'max:12'],
            'meses.*.isento' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensagensComuns(): array
    {
        return [
            'descricao.required' => 'A descrição da pausa é obrigatória.',
            'descricao.max' => 'A descrição da pausa não pode ultrapassar 200 caracteres.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_fim.required' => 'A data de fim é obrigatória.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
            'cobra_propina.required' => 'Indique se a pausa cobra propina.',
            'meses.*.ano.required' => 'O ano do mês é obrigatório.',
            'meses.*.mes.required' => 'O mês é obrigatório.',
            'meses.*.mes.min' => 'O mês tem de estar entre 1 e 12.',
            'meses.*.mes.max' => 'O mês tem de estar entre 1 e 12.',
        ];
    }

    /**
     * As datas têm de estar dentro do ano lectivo e não podem sobrepor-se a outra pausa do mesmo ano.
     */
    protected function validarDatas(Validator $validator, int $anoLectivoId, ?int $ignorarPausaId = null): void
    {
        $erros = $validator->errors();

        if ($erros->hasAny(['data_inicio', 'data_fim'])) {
            return;
        }

        $ano = AnoLectivo::query()->find($anoLectivoId);

        if ($ano === null) {
            return;
        }

        $inicio = (string) $this->input('data_inicio');
        $fim = (string) $this->input('data_fim');

        if ($inicio < $ano->data_inicio->toDateString() || $fim > $ano->data_fim->toDateString()) {
            $erros->add('data_inicio', 'As datas da pausa têm de estar dentro do ano lectivo.');

            return;
        }

        $sobrepoe = PausaCobranca::query()
            ->where('ano_lectivo_id', $anoLectivoId)
            ->when($ignorarPausaId !== null, fn ($query) => $query->where('id', '!=', $ignorarPausaId))
            ->where('data_inicio', '<=', $fim)
            ->where('data_fim', '>=', $inicio)
            ->exists();

        if ($sobrepoe) {
            $erros->add('data_inicio', 'Já existe uma pausa de cobrança sobreposta neste ano lectivo.');
        }
    }
}
