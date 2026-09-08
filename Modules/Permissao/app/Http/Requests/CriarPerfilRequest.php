<?php

namespace Modules\Permissao\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarPerfilRequest extends BaseRequest
{
    // Reutilizado no store (POST) e no update (PUT) do perfil — a acção
    // exigida depende do verbo, já que não há dois FormRequests para isto.
    public function authorize(): bool
    {
        $acao = $this->isMethod('post') ? 'criar' : 'editar';

        return $this->user()?->can("autorizacao.{$acao}") ?? false;
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
