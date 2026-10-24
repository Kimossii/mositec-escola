<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Modules\Financeiro\Enums\TipoMetodoPagamento;

/**
 * Dados extra do método de pagamento, consoante o tipo. Transferência/Depósito usam dados bancários;
 * Multicaixa usa os mesmos dados bancários (é uma transferência); Transferência Express usa telefone/banco; Carteira digital
 * usa titular/telefone (obrigatório) e um identificador opcional; os restantes não têm dados.
 */
trait ValidaDadosMetodoPagamento
{
    protected function normalizarDadosMetodoPagamento(): void
    {
        foreach (['titular', 'banco', 'numero_conta', 'telefone'] as $campo) {
            if (is_string($this->input($campo))) {
                $valor = trim($this->input($campo));
                $this->merge([$campo => $valor === '' ? null : $valor]);
            }
        }

        if (is_string($this->input('iban'))) {
            $iban = preg_replace('/\s+/', '', strtoupper($this->input('iban'))) ?? '';
            $this->merge(['iban' => $iban === '' ? null : $iban]);
        }
    }

    /** @return array<string, mixed> */
    protected function regrasDosDadosMetodoPagamento(): array
    {
        $tipo = is_numeric($this->input('tipo')) ? TipoMetodoPagamento::tryFrom((int) $this->input('tipo')) : null;

        if (in_array($tipo, [TipoMetodoPagamento::TRANSFERENCIA_BANCARIA, TipoMetodoPagamento::MULTICAIXA], true)) {
            return [
                'titular' => ['nullable', 'string', 'max:150'],
                'banco' => ['nullable', 'string', 'max:100'],
                'iban' => ['nullable', 'string', 'max:34', 'regex:/^[A-Z]{2}[0-9A-Z]{1,32}$/'],
                'numero_conta' => ['nullable', 'string', 'max:40'],
            ];
        }

        if ($tipo === TipoMetodoPagamento::TRANSFERENCIA_EXPRESS) {
            return [
                'banco' => ['nullable', 'string', 'max:100'],
                'telefone' => ['nullable', 'string', 'max:30'],
            ];
        }

        if ($tipo === TipoMetodoPagamento::CARTEIRA_DIGITAL) {
            return [
                'titular' => ['nullable', 'string', 'max:150'],
                'telefone' => ['required', 'string', 'max:30'],
                'numero_conta' => ['nullable', 'string', 'max:40'],
            ];
        }

        return [];
    }

    /** @return array<string, string> */
    protected function mensagensDosDadosMetodoPagamento(): array
    {
        return [
            'titular.max' => 'O titular não pode ultrapassar 150 caracteres.',
            'banco.max' => 'O banco não pode ultrapassar 100 caracteres.',
            'iban.max' => 'O IBAN não pode ultrapassar 34 caracteres.',
            'iban.regex' => 'O IBAN tem de estar em maiúsculas e sem espaços.',
            'numero_conta.max' => 'O número de conta não pode ultrapassar 40 caracteres.',
            'telefone.required' => 'O telefone é obrigatório para uma carteira digital.',
            'telefone.max' => 'O telefone não pode ultrapassar 30 caracteres.',
        ];
    }
}
