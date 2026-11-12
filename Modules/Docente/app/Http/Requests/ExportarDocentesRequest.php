<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;

/** A exportação dos docentes: o formato e os mesmos filtros da lista. */
class ExportarDocentesRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('docente.exportar') ?? false;
    }

    public function rules(): array
    {
        return [
            'formato' => ['required', 'string'],
            'pesquisa' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:0,1'],
            'acesso' => ['nullable', 'in:com,sem'],
        ];
    }

    public function messages(): array
    {
        return [
            'formato.required' => 'Escolha o formato da exportação.',
            'estado.in' => 'O estado indicado é inválido.',
            'acesso.in' => 'O acesso indicado é inválido.',
            'pesquisa.max' => 'A pesquisa não pode ultrapassar 100 caracteres.',
        ];
    }
}
