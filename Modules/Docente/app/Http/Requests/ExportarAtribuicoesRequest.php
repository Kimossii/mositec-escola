<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;

/** A exportação das atribuições: o formato e os mesmos filtros da lista. */
class ExportarAtribuicoesRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('atribuicao-docente.exportar') ?? false;
    }

    public function rules(): array
    {
        return [
            'formato' => ['required', 'string'],
            'ano_lectivo_id' => ['nullable', 'integer'],
            'turma_id' => ['nullable', 'integer'],
            'docente_id' => ['nullable', 'integer'],
            'disciplina_id' => ['nullable', 'integer'],
            'papel' => ['nullable', 'in:0,1'],
            'estado_vigencia' => ['nullable', 'in:agendada,em_curso,terminada'],
            'pesquisa' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'formato.required' => 'Escolha o formato da exportação.',
            'papel.in' => 'O papel indicado é inválido.',
            'estado_vigencia.in' => 'O estado da vigência indicado é inválido.',
            'pesquisa.max' => 'A pesquisa não pode ultrapassar 100 caracteres.',
        ];
    }
}
