<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;

class AlterarEstadoAnoLectivoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ano-lectivo.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoAnoLectivo::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'O novo estado é obrigatório.',
        ];
    }
}
