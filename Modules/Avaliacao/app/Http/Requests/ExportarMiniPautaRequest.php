<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class ExportarMiniPautaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.ver') ?? false;
    }

    public function rules(): array
    {
        return [
            'turma' => ['required', 'integer'],
            'disciplina' => ['required', 'integer'],
            'formato' => ['required', 'string', 'in:pdf,excel'],
            'detalhe' => ['nullable', 'string', 'in:competencias'],
            'periodo' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return MensagensDeAvaliacao::todas();
    }
}
