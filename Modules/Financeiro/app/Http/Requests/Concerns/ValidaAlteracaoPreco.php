<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Rules\ValorMonetario;

/**
 * Regras partilhadas pela pré-visualização e pela confirmação da alteração de preço (spec §14). A escolha não tem
 * valor por omissão; as duas permissões são exigidas (Q12).
 */
trait ValidaAlteracaoPreco
{
    public function authorize(): bool
    {
        $utilizador = $this->user();

        return $utilizador !== null && $utilizador->can('propina.ajustar') && $utilizador->can('plano-propina.editar');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function regrasDoPedido(): array
    {
        return [
            'valor' => ['required', new ValorMonetario(false)],
            'mes_efeito' => ['required', 'string', 'date_format:Y-m'],
            'modo' => ['required', Rule::enum(ModoAlteracaoPreco::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensagensDoPedido(): array
    {
        return [
            'valor.required' => 'Indique o novo valor.',
            'mes_efeito.required' => 'Indique o mês de efeito.',
            'mes_efeito.date_format' => 'O mês de efeito é inválido.',
            'modo.required' => 'Escolha como tratar as propinas já geradas.',
            'modo.enum' => 'Escolha como tratar as propinas já geradas.',
        ];
    }
}
