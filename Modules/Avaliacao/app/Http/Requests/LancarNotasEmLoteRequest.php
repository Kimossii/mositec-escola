<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

/** Todas as notas alteradas no ecrã, enviadas de uma vez: avaliações da mini-pauta, exames e recursos. */
class LancarNotasEmLoteRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma_id' => ['required', 'integer'],
            'disciplina_id' => ['required', 'integer'],
            'notas' => ['nullable', 'array', 'max:500'],
            'notas.*.instrumento_id' => ['required', 'integer'],
            'notas.*.inscricao_disciplina_id' => ['required', 'integer'],
            'notas.*.valor' => ['required', 'string', 'max:6'],
            'notas.*.motivo' => ['nullable', 'string', 'max:500'],
            'exames' => ['nullable', 'array', 'max:500'],
            'exames.*.inscricao_disciplina_id' => ['required', 'integer'],
            'exames.*.valor' => ['required', 'string', 'max:6'],
            'exames.*.motivo' => ['nullable', 'string', 'max:500'],
            'recursos' => ['nullable', 'array', 'max:500'],
            'recursos.*.inscricao_disciplina_id' => ['required', 'integer'],
            'recursos.*.valor' => ['required', 'string', 'max:6'],
            'recursos.*.motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            ...MensagensDeAvaliacao::todas(),
            'notas.max' => 'Demasiadas notas de uma vez.',
            'exames.max' => 'Demasiados exames de uma vez.',
            'recursos.max' => 'Demasiados recursos de uma vez.',
        ];
    }
}
