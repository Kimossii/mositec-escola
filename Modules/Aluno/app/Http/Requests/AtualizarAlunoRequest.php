<?php

namespace Modules\Aluno\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class AtualizarAlunoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('aluno.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome_completo' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'telefone_alternativo' => ['nullable', 'string', 'max:50'],
            'data_nascimento' => ['required', 'date'],
            'sexo' => ['nullable', 'integer', Rule::in([0, 1, 2])],
            'numero_identificacao' => [
                'required',
                'string',
                'max:100',
                Rule::unique('dados_pessoas', 'numero_identificacao')->ignore($this->route('aluno')?->dados_pessoa_id),
            ],
            'foto' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome_completo.required' => 'O nome completo é obrigatório.',
            'data_nascimento.required' => 'A data de nascimento é obrigatória.',
            'numero_identificacao.required' => 'O número de identificação é obrigatório.',
            'numero_identificacao.unique' => 'Já existe uma pessoa com este número de identificação.',
            'foto.image' => 'A foto deve ser uma imagem válida.',
            'foto.mimes' => 'A foto deve ser um ficheiro PNG, JPG, JPEG ou WEBP.',
            'foto.max' => 'A foto não pode exceder 2MB.',
        ];
    }
}
