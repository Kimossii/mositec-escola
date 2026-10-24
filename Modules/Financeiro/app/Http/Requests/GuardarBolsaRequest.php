<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Closure;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;

class GuardarBolsaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('bolsa') ? 'bolsa.editar' : 'bolsa.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            // Só na criação: a bolsa nasce da matrícula escolhida (aluno + ano lectivo).
            'matricula_id' => [$this->route('bolsa') ? 'sometimes' : 'required', 'integer', Rule::exists('matriculas', 'id')->where(fn ($q) => $q->where('tenant_id', app(TenantContext::class)->id())->whereNull('deleted_at'))],
            'nome' => ['required', 'string', 'max:150'],
            'percentagem' => ['required', 'regex:/^\d{1,3}([.,]\d{1,2})?$/', function (string $atributo, mixed $valor, Closure $falha) {
                $numero = (float) str_replace(',', '.', (string) $valor);

                if ($numero <= 0 || $numero > 100) {
                    $falha('A percentagem tem de estar entre 0,01 e 100.');
                }
            }],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'matricula_id.required' => 'Escolha o aluno (matrícula).',
            'nome.required' => 'Indique o nome da bolsa.',
            'percentagem.required' => 'Indique a percentagem coberta.',
            'percentagem.regex' => 'Percentagem inválida (ex.: 50 ou 12,5).',
            'data_inicio.required' => 'Indique o início da bolsa.',
            'data_fim.required' => 'Indique o fim da bolsa.',
            'data_fim.after_or_equal' => 'O fim não pode ser anterior ao início.',
            'motivo.required' => 'Indique o motivo da bolsa.',
            'motivo.min' => 'Indique o motivo (mínimo 5 caracteres).',
        ];
    }
}
