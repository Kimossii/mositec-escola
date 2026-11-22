<?php

namespace Modules\Usuario\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Usuario\Enums\AcaoAcessoDocumento;

class FiltrarAcessosDocumentosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:acesso-documento-pessoa.ver` na rota
    }

    public function rules(): array
    {
        return [
            'pessoa' => ['nullable', 'string', 'max:100'],
            'utilizador' => ['nullable', 'string', 'max:100'],
            'acao' => ['nullable', Rule::enum(AcaoAcessoDocumento::class)],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
        ];
    }

    public function filtros(): array
    {
        return array_filter($this->validated(), fn ($v) => $v !== null && $v !== '');
    }
}
