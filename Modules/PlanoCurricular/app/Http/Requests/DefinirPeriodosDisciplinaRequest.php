<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class DefinirPeriodosDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        $anoLectivoId = $this->route('planoCurricularAnoLectivo')->ano_lectivo_id;

        return [
            'periodo_ids' => ['array'],
            'periodo_ids.*' => [
                'integer',
                Rule::exists('periodos', 'id')->where(fn ($query) => $query->where('ano_lectivo_id', $anoLectivoId)),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'periodo_ids.*.exists' => 'Um dos períodos indicados não pertence ao ano lectivo desta aplicação.',
        ];
    }
}
