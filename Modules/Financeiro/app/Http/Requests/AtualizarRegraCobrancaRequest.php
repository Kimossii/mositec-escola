<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Rules\ValorMonetario;
use Modules\Financeiro\Support\Multa;

class AtualizarRegraCobrancaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('regra-cobranca.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'dia_vencimento' => 'required|integer|between:1,28',
            'dias_tolerancia' => 'required|integer|between:0,90',
            'permite_pagamento_parcial' => 'required|boolean',
            'permite_pagamento_antecipado' => 'required|boolean',
            'gerar_automaticamente' => 'required|boolean',
            'permite_negociacao' => 'required|boolean',
            'desconto_maximo_negociacao' => 'required|integer|between:0,100',
            'multa_activa' => 'sometimes|boolean',
        ] + ($this->multaActivaNoPedido() ? [
            'escaloes' => [
                'required',
                'array',
                'min:1',
                'max:' . Multa::MAX_ESCALOES,
            ],
            'escaloes.*' => 'array',
            'escaloes.*.dias_atraso' => ['required', 'integer', 'min:1', 'max:' . Multa::MAX_DIAS_ATRASO],
            'escaloes.*.tipo' => ['required', Rule::enum(TipoMulta::class)],
            'escaloes.*.valor' => ['required', $this->regraDoValor(...)],
        ] : []);
    }

    /**
     * Só com a multa activa os escalões são validados e substituídos; desligada (ou ausente) ficam intactos.
     */
    private function multaActivaNoPedido(): bool
    {
        return $this->has('multa_activa') && $this->boolean('multa_activa');
    }

    /**
     * O valor depende do tipo do mesmo escalão: percentagem (até 2 casas, 0–100%) ou montante
     * na moeda da escola.
     */
    private function regraDoValor(string $atributo, mixed $valor, Closure $falhar): void
    {
        preg_match('/^escaloes\.(\d+)\.valor$/', $atributo, $partes);
        $tipo = TipoMulta::tryFrom((int) $this->input("escaloes.{$partes[1]}.tipo", -1));

        if ($tipo === null) {
            return; // o erro do tipo já é reportado à parte
        }

        if (! is_int($valor) && ! is_float($valor) && ! is_string($valor)) {
            $falhar('O valor é inválido.');

            return;
        }

        if ($tipo === TipoMulta::PERCENTAGEM) {
            try {
                Multa::percentagemParaPontosBase($valor);
            } catch (InvalidArgumentException) {
                $falhar('A percentagem é inválida: use um valor superior a 0 e até 100, com no máximo 2 casas decimais (ex.: 2,5).');
            }

            return;
        }

        (new ValorMonetario(false))->validate($atributo, $valor, $falhar);
    }

    public function after(): array
    {
        return [function (Validator $validador) {
            if (! $this->multaActivaNoPedido() || $validador->errors()->isNotEmpty()) {
                return;
            }

            $anterior = 0;
            foreach (array_values($this->input('escaloes') ?? []) as $posicao => $escalao) {
                $dias = (int) $escalao['dias_atraso'];

                if ($dias <= $anterior) {
                    $validador->errors()->add("escaloes.{$posicao}.dias_atraso", 'Os dias de atraso têm de ser superiores aos do escalão anterior.');

                    return;
                }

                $anterior = $dias;
            }
        }];
    }

    public function messages(): array
    {
        return [
            'escaloes.required' => 'Com a multa activa, defina pelo menos um escalão.',
            'escaloes.min' => 'Com a multa activa, defina pelo menos um escalão.',
            'escaloes.max' => 'Pode definir no máximo ' . Multa::MAX_ESCALOES . ' escalões.',
            'escaloes.array' => 'Os escalões são inválidos.',
            'escaloes.*.array' => 'O escalão é inválido.',
            'escaloes.*.dias_atraso.required' => 'Indique os dias de atraso do escalão.',
            'escaloes.*.dias_atraso.integer' => 'Os dias de atraso têm de ser um número inteiro.',
            'escaloes.*.dias_atraso.min' => 'Os dias de atraso têm de ser pelo menos 1.',
            'escaloes.*.dias_atraso.max' => 'Os dias de atraso não podem exceder ' . Multa::MAX_DIAS_ATRASO . '.',
            'escaloes.*.tipo.required' => 'Escolha o tipo de multa.',
            'escaloes.*.tipo.enum' => 'O tipo de multa é inválido.',
            'escaloes.*.valor.required' => 'Indique o valor da multa.',
            'dia_vencimento.between' => 'O dia de vencimento tem de estar entre 1 e 28.',
            'dias_tolerancia.between' => 'Os dias de tolerância têm de estar entre 0 e 90.',
            'desconto_maximo_negociacao.between' => 'O desconto máximo tem de estar entre 0% e 100%.',
        ];
    }
}
