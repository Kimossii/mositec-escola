<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;

class FiltrarEnviosEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:envio-email.ver` na rota
    }

    public function rules(): array
    {
        return [
            'estado' => ['nullable', Rule::in(array_map(fn (EstadoEnvioEmail $e) => $e->value, EstadoEnvioEmail::cases()))],
            'categoria' => ['nullable', Rule::enum(CategoriaEmail::class)],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
        ];
    }

    public function filtros(): array
    {
        return array_filter($this->validated(), fn ($v) => $v !== null && $v !== '');
    }
}
