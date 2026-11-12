<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;

class ExportarRecebimentosRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pagamento.exportar') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['formato' => ['required', 'string']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['formato.required' => 'Escolha o formato da exportação.'];
    }
}
