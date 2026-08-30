<?php

namespace Modules\Permissao\Http\Requests;

use App\Http\Requests\BaseRequest;

class SincronizarPermissoesPerfilRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-permissoes') ?? false;
    }

    public function rules(): array
    {
        return [
            'celulas' => 'present|array',
            'celulas.*.modulo_id' => 'required|integer|exists:modulos,id',
            'celulas.*.acao_id' => 'required|integer|exists:acoes,id',
        ];
    }
}
