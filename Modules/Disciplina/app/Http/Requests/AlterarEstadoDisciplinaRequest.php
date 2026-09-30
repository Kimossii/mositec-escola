<?php

namespace Modules\Disciplina\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Enums\Estado;

class AlterarEstadoDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('disciplina.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(Estado::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'O novo estado é obrigatório.',
        ];
    }
}
