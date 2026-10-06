<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarPlanoCurricularRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return [
            'nivel_academico_id' => [
                'required',
                'integer',
                Rule::exists('niveis_academicos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
            ],
            'curso_id' => [
                'nullable',
                'integer',
                Rule::exists('cursos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
            ],
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('planos_curriculares', 'codigo')
                    ->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))
                    ->ignore($this->route('planoCurricular')),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'nivel_academico_id.required' => 'O nível académico é obrigatório.',
            'nivel_academico_id.exists' => 'O nível académico indicado não pertence a este estabelecimento.',
            'curso_id.exists' => 'O curso indicado não pertence a este estabelecimento.',
            'codigo.required' => 'O código do plano é obrigatório.',
            'codigo.unique' => 'Já existe um plano curricular com este código neste estabelecimento.',
            'nome.required' => 'O nome do plano é obrigatório.',
        ];
    }
}
