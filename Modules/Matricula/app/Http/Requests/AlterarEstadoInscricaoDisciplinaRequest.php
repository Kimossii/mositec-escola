<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;

class AlterarEstadoInscricaoDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoInscricaoDisciplinaEnum::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'O novo estado é obrigatório.',
        ];
    }
}
