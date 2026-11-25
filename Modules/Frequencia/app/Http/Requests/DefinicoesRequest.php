<?php

namespace Modules\Frequencia\Http\Requests;

use App\Http\Requests\BaseRequest;

class DefinicoesRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('frequencia.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'janela_correccao_dias' => ['required', 'integer', 'between:1,60'],
        ];
    }

    public function messages(): array
    {
        return [
            'janela_correccao_dias.required' => 'A janela de correcção é obrigatória.',
            'janela_correccao_dias.integer' => 'A janela de correcção tem de ser um número de dias.',
            'janela_correccao_dias.between' => 'A janela de correcção tem de ter entre 1 e 60 dias.',
        ];
    }
}
