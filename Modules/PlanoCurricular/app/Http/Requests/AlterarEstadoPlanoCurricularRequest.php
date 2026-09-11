<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Enums\Estado;

class AlterarEstadoPlanoCurricularRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        return ['estado' => ['required', new Enum(Estado::class)]];
    }
}
