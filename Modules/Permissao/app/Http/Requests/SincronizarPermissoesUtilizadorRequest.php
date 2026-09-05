<?php

namespace Modules\Permissao\Http\Requests;

use App\Http\Requests\BaseRequest;

class SincronizarPermissoesUtilizadorRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('autorizacao.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'celulas' => 'present|array',
            'celulas.*.modulo_id' => 'required|integer|exists:modulos,id',
            'celulas.*.acao_id' => 'required|integer|exists:acoes,id',
            'celulas.*.permitido' => 'required|boolean',
        ];
    }
}
