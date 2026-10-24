<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPausaCobranca;

class AtualizarPausaCobrancaRequest extends BaseRequest
{
    use ValidaPausaCobranca;

    public function authorize(): bool
    {
        return $this->user()?->can('pausa-cobranca.editar') ?? false;
    }

    public function rules(): array
    {
        return $this->regrasComuns();
    }

    public function messages(): array
    {
        return $this->mensagensComuns();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $pausa = $this->route('pausa');

            $this->validarDatas($validator, (int) $pausa->ano_lectivo_id, (int) $pausa->id);
        });
    }
}
