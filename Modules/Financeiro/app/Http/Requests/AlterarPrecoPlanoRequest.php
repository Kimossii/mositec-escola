<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Http\Requests\Concerns\ValidaAlteracaoPreco;

class AlterarPrecoPlanoRequest extends BaseRequest
{
    use ValidaAlteracaoPreco;

    public function rules(): array
    {
        return array_merge($this->regrasDoPedido(), [
            'motivo' => ['required', 'string', 'min:' . AlterarPrecoPlanoAction::MOTIVO_MINIMO, 'max:' . AlterarPrecoPlanoAction::MOTIVO_MAXIMO],
            'impressao_digital' => ['required', 'string', 'size:64'],
            'confirmar_valores_diferentes' => ['nullable', 'boolean'],
        ]);
    }

    public function messages(): array
    {
        return array_merge($this->mensagensDoPedido(), [
            'motivo.required' => 'Indique o motivo da alteração de preço.',
            'motivo.min' => 'O motivo tem de ter pelo menos ' . AlterarPrecoPlanoAction::MOTIVO_MINIMO . ' caracteres.',
            'motivo.max' => 'O motivo não pode ter mais de ' . AlterarPrecoPlanoAction::MOTIVO_MAXIMO . ' caracteres.',
            'impressao_digital.required' => 'Faça a pré-visualização antes de confirmar.',
            'impressao_digital.size' => 'Faça a pré-visualização antes de confirmar.',
        ]);
    }
}
