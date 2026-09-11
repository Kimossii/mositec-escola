<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class ConfirmarAnoLectivoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;
        $planoId = $this->route('planoCurricular')->id;

        return [
            'ano_lectivo_id' => [
                'required',
                'integer',
                Rule::exists('ano_lectivos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
                Rule::unique('plano_curricular_anos_lectivos', 'ano_lectivo_id')
                    ->where(fn ($q) => $q->where('plano_curricular_id', $planoId)),
            ],
            'observacoes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'ano_lectivo_id.exists' => 'O ano lectivo indicado não pertence a este estabelecimento.',
            'ano_lectivo_id.unique' => 'Este plano já está confirmado para este ano lectivo.',
        ];
    }
}
