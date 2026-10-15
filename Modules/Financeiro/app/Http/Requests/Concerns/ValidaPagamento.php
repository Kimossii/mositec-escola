<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\LinhaDeDistribuicao;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Rules\ValorMonetario;
use Modules\Matricula\Models\Matricula;

/** Regras comuns ao registo e à pré-visualização de um pagamento (D5–D10). As regras de negócio vivem na Action. */
trait ValidaPagamento
{
    public function authorize(): bool
    {
        return $this->user()?->can('pagamento.criar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['referencia', 'observacao'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo)) === '' ? null : trim($this->input($campo))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $hoje = app(RelogioDoTenant::class)->hoje()->toDateString();
        $matricula = $this->route('matricula');
        $minimo = $matricula instanceof Matricula ? $matricula->data_matricula?->toDateString() : null; // O3

        return [
            'metodo_pagamento_id' => ['required', 'integer', Rule::exists('metodos_pagamento', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('estado', Estado::ATIVO->value))],
            'valor' => ['required', new ValorMonetario(false)],
            'data_pagamento' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:' . $hoje, function (string $atributo, mixed $valor, Closure $falhar) use ($minimo) {
                if ($minimo !== null && is_string($valor) && $valor < $minimo) {
                    $falhar('A data do pagamento não pode ser anterior à data da matrícula (' . CarbonImmutable::parse($minimo)->format('d/m/Y') . ').');
                }
            }],
            'referencia' => ['nullable', 'string', 'max:' . RegistarPagamentoDTO::REFERENCIA_MAXIMA],
            'observacao' => ['nullable', 'string', 'max:' . RegistarPagamentoDTO::OBSERVACAO_MAXIMA],
            'valor_credito' => ['nullable', new ValorMonetario(true)],
            'modo_distribuicao' => ['required', Rule::in([LinhaDeDistribuicao::AUTOMATICA, LinhaDeDistribuicao::EXPLICITA])],
            'distribuicao' => ['exclude_unless:modo_distribuicao,' . LinhaDeDistribuicao::EXPLICITA, 'present', 'array', 'max:60'],
            'distribuicao.*.propina_id' => ['required', 'integer', 'distinct'],
            'distribuicao.*.valor' => ['required', new ValorMonetario(false)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'metodo_pagamento_id.required' => 'Escolha o método de pagamento.',
            'metodo_pagamento_id.exists' => 'Escolha um método de pagamento activo.',
            'valor.required' => 'Indique o valor recebido.',
            'data_pagamento.required' => 'Indique a data do pagamento.',
            'data_pagamento.date_format' => 'A data do pagamento é inválida.',
            'data_pagamento.before_or_equal' => 'A data do pagamento não pode ser futura.',
            'referencia.max' => 'A referência tem no máximo ' . RegistarPagamentoDTO::REFERENCIA_MAXIMA . ' caracteres.',
            'observacao.max' => 'A observação tem no máximo ' . RegistarPagamentoDTO::OBSERVACAO_MAXIMA . ' caracteres.',
            'modo_distribuicao.required' => 'Indique se a distribuição é automática ou explícita.',
            'distribuicao.present' => 'Envie a distribuição (pode ser vazia).',
            'distribuicao.max' => 'Demasiadas linhas na distribuição.',
            'distribuicao.*.propina_id.distinct' => 'Cada propina só pode aparecer uma vez na distribuição.',
            'distribuicao.*.valor.required' => 'Indique o valor de cada linha.',
        ];
    }
}
