<?php

namespace Modules\Aluno\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class CriarAlunoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('aluno.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'dados_pessoa_id' => [
                'nullable',
                'integer',
                'exists:dados_pessoas,id',
                Rule::unique('alunos', 'dados_pessoa_id'),
            ],
            'nome_completo' => ['required_without:dados_pessoa_id', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'telefone_alternativo' => ['nullable', 'string', 'max:50'],
            'data_nascimento' => ['required_without:dados_pessoa_id', 'date'],
            'sexo' => ['nullable', 'integer', Rule::in([0, 1, 2])],
            'numero_identificacao' => [
                'required_without:dados_pessoa_id',
                'string',
                'max:100',
                Rule::unique('dados_pessoas', 'numero_identificacao'),
            ],
            'foto' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'dados_pessoa_id.exists' => 'A pessoa seleccionada não existe.',
            'dados_pessoa_id.unique' => 'Esta pessoa já está associada a um aluno.',
            'nome_completo.required_without' => 'O nome completo é obrigatório.',
            'data_nascimento.required_without' => 'A data de nascimento é obrigatória.',
            'numero_identificacao.required_without' => 'O número de identificação é obrigatório.',
            'numero_identificacao.unique' => 'Já existe uma pessoa com este número de identificação.',
            'foto.image' => 'A foto deve ser uma imagem válida.',
            'foto.mimes' => 'A foto deve ser um ficheiro PNG, JPG, JPEG ou WEBP.',
            'foto.max' => 'A foto não pode exceder 2MB.',
        ];
    }
}
