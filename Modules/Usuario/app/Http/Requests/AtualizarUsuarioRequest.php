<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class AtualizarUsuarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-usuarios') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'perfil' => 'required|in:admin_escola,secretario,professor,aluno,encarregado',
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'celulas' => 'nullable|array',
            'celulas.*.modulo_id' => 'required_with:celulas|integer|exists:modulos,id',
            'celulas.*.acao_id' => 'required_with:celulas|integer|exists:acoes,id',
            'celulas.*.permitido' => 'required_with:celulas|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'perfil.required' => 'O perfil é obrigatório.',
            'perfil.in' => 'O perfil indicado é inválido.',
            'email.email' => 'Informe um email válido.',
            'email.unique' => 'Este email já está em uso.',
        ];
    }
}
