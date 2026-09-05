<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;

class AtualizarEventoCalendarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ano-lectivo.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'tipo' => ['required', new Enum(TipoEventoCalendario::class)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'dia_inteiro' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
            'dia_inteiro.required' => 'É necessário indicar se o evento ocorre o dia inteiro.',
        ];
    }
}
