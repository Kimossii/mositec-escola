<?php

namespace Modules\Permissao\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarPerfilRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-permissoes') ?? false;
    }

    public function rules(): array
    {
        return [
            'descricao' => 'required|string|max:255',
            'estado' => 'nullable|in:0,1',
        ];
    }

    public function messages(): array
    {
        return [
            'descricao.required' => 'O nome do perfil é obrigatório.',
            'descricao.max' => 'O nome do perfil não pode ter mais de 255 caracteres.',
        ];
    }
}
