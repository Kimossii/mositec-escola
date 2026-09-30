<?php

namespace Modules\Infraestrutura\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Infraestrutura\Enums\EstadoSala;

class AlterarEstadoSalaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestrutura.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoSala::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'O novo estado é obrigatório.',
        ];
    }
}
