<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;

class AdicionarDisciplinaRequest extends BaseRequest
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
            'disciplina_id' => [
                'required',
                'integer',
                Rule::exists('disciplinas', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
                Rule::unique('plano_curricular_disciplinas', 'disciplina_id')
                    ->where(fn ($q) => $q->where('plano_curricular_id', $planoId)),
            ],
            'carga_horaria' => ['nullable', 'integer', 'min:1'],
            'creditos' => ['nullable', 'integer', 'min:1'],
            'componente' => ['nullable', new Enum(ComponentePlanoCurricular::class)],
            'tipo' => ['required', new Enum(TipoDisciplinaPlano::class)],
            'obrigatoria' => ['required', 'boolean'],
            'ordem' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'disciplina_id.exists' => 'A disciplina indicada não pertence a este estabelecimento.',
            'disciplina_id.unique' => 'Esta disciplina já está associada a este plano.',
        ];
    }
}
