<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Actions\CriarAcordoAction;

class CriarAcordoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('acordo.criar') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Só matrículas desta escola: um id de outra escola responde como um que não existe.
            'matricula_id' => ['required', 'integer', Rule::exists('matriculas', 'id')->where(fn ($q) => $q->where('tenant_id', app(TenantContext::class)->id())->whereNull('deleted_at'))],
            'propinas' => ['required', 'array', 'min:1'],
            'propinas.*' => ['integer'],
            'desconto_multa_pct' => ['required', 'integer', 'between:0,100'],
            'prestacoes' => ['required', 'integer', 'between:1,'.CriarAcordoAction::MAX_PRESTACOES],
            'primeiro_vencimento' => ['required', 'date'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'matricula_id.required' => 'Escolha o aluno (matrícula).',
            'matricula_id.exists' => 'A matrícula indicada não existe.',
            'propinas.required' => 'Escolha pelo menos uma propina.',
            'propinas.min' => 'Escolha pelo menos uma propina.',
            'primeiro_vencimento.required' => 'Indique a data da primeira prestação.',
            'motivo.required' => 'Indique o motivo do acordo.',
            'motivo.min' => 'Indique o motivo (mínimo 5 caracteres).',
        ];
    }
}
