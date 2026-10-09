<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Enums\Estado;

class AlterarEstadoPlanoPropinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-propina.editar') ?? false;
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
