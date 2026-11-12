<?php

namespace Modules\Avaliacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class GuardarDefinicoesDeAvaliacaoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('nota.configurar') ?? false;
    }

    public function rules(): array
    {
        return [
            'max_avaliacoes' => ['required', 'integer', 'between:1,30'],
            'min_avaliacoes' => ['required', 'integer', 'between:1,30'],
        ];
    }

    public function messages(): array
    {
        return [
            ...MensagensDeAvaliacao::todas(),
            'max_avaliacoes.required' => 'Indique o máximo de avaliações por trimestre.',
            'max_avaliacoes.between' => 'O máximo de avaliações por trimestre tem de estar entre 1 e 30.',
            'min_avaliacoes.required' => 'Indique o mínimo esperado de avaliações.',
            'min_avaliacoes.between' => 'O mínimo esperado de avaliações tem de estar entre 1 e 30.',
        ];
    }
}
