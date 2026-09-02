<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;

class CriarEventoCalendarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'tipo' => ['required', new Enum(TipoEventoCalendario::class)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'dia_inteiro' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'O título do evento é obrigatório.',
            'tipo.required' => 'O tipo de evento é obrigatório.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_fim.required' => 'A data de fim é obrigatória.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        ];
    }
}
