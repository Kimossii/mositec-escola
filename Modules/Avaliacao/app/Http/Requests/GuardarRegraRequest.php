<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Enums\TratamentoDeNegativas;

class GuardarRegraRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'ano_lectivo_id' => ['required', 'integer'],
            'regra_id' => ['nullable', 'integer'],
            'nova' => ['nullable', 'boolean'],
            'sigla_prova' => ['nullable', 'string', 'max:10'],
            'nome' => ['required', 'string', 'max:120'],
            'nivel_ids' => ['present', 'array', 'max:60'],
            'nivel_ids.*' => ['integer'],
            'com_exame' => ['required', 'boolean'],
            'com_recurso' => ['required', 'boolean'],
            'exame_usa_mfd_arredondada' => ['sometimes', 'boolean'],
            'escala_maxima' => ['required', 'integer', 'between:1,100'],
            'nota_minima' => ['required', 'numeric', 'min:0'],
            'peso_mac' => ['required', 'numeric', 'between:0,100'],
            'peso_pt' => ['required', 'numeric', 'between:0,100'],
            'peso_mt1' => ['nullable', 'numeric', 'between:0,100'],
            'peso_mt2' => ['nullable', 'numeric', 'between:0,100'],
            'peso_mt3' => ['nullable', 'numeric', 'between:0,100'],
            'peso_mfd_exame' => ['nullable', 'numeric', 'between:0,100'],
            'peso_exame' => ['nullable', 'numeric', 'between:0,100'],
            'recurso_max_disciplinas' => ['nullable', 'integer', 'between:0,30'],
            'disciplinas_chave' => ['nullable', 'array', 'max:30'],
            'disciplinas_chave.*' => ['integer'],
            'casas_decimais' => ['required', 'integer', 'between:0,4'],
            'modo_arredondamento' => ['required', Rule::in(array_column(ModoArredondamento::cases(), 'value'))],
            'tratamento_negativas' => ['required', Rule::in(array_column(TratamentoDeNegativas::cases(), 'value'))],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
