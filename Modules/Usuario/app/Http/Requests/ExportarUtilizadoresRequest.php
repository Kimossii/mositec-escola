<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Usuario\Services\ExportacaoDeUtilizadoresService;

/** A exportação da lista de utilizadores: o formato e os mesmos filtros do ecrã. */
class ExportarUtilizadoresRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('usuario.exportar') ?? false;
    }

    public function rules(): array
    {
        return [
            'formato' => ['required', 'string'],
            'perfil' => ['nullable', 'string', Rule::in(array_keys(ExportacaoDeUtilizadoresService::PERFIS))],
            'pesquisa' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return [
            'formato.required' => 'Escolha o formato da exportação.',
            'perfil.in' => 'O perfil indicado é inválido.',
            'pesquisa.max' => 'A pesquisa não pode ultrapassar 100 caracteres.',
            'estado.in' => 'O estado indicado é inválido.',
        ];
    }
}
