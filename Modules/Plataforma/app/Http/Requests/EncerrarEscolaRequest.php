<?php

namespace Modules\Plataforma\Http\Requests;

use Illuminate\Validation\Validator;

/**
 * Salvaguarda da interface, não regra de negócio: encerrar é terminal, por isso o operador tem de
 * escrever o código da escola. A Action não conhece esta confirmação (o comando tem a sua).
 */
class EncerrarEscolaRequest extends PedidoDeEscolaRequest
{
    /** Só o formato do motivo (opcional): o tecto exacto é da EncerrarTenantAction. */
    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validador) {
                $codigo = $this->route('tenant')->codigo;
                $escrito = $this->input('confirmacao');

                // Qualquer coisa que não seja exactamente o código (em falta, vazio, outro tipo, outra escola)
                // dá a mesma mensagem, que diz que código escrever.
                if (! is_string($escrito) || $escrito !== $codigo) {
                    $validador->errors()->add('confirmacao', "Escreva exactamente o código da escola ({$codigo}) para confirmar o encerramento.");
                }
            },
        ];
    }
}
