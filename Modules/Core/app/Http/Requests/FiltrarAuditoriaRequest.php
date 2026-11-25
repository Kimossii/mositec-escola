<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FiltrarAuditoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:auditoria.ver` na rota
    }

    public function rules(): array
    {
        return [
            'accao' => ['nullable', 'string', 'max:100'],
            'utilizador' => ['nullable', 'string', 'max:100'],
            'entidade_tipo' => ['nullable', 'string', 'max:100'],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
        ];
    }

    public function filtros(): array
    {
        return array_filter($this->validated(), fn ($v) => $v !== null && $v !== '');
    }
}
