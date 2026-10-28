<?php

namespace Modules\Aluno\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\ValidaProvinciaMunicipio;

class AtualizarAlunoRequest extends BaseRequest
{
    use ValidaProvinciaMunicipio;

    public function authorize(): bool
    {
        return $this->user()?->can('aluno.editar') ?? false;
    }

    public function rules(): array
    {
        return array_merge([
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
            'foto' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ], $this->regrasDeProvinciaMunicipio('provincia_nascimento', 'municipio_nascimento'));
    }

    public function messages(): array
    {
        return array_merge([
            'nome_completo.required' => 'O nome completo é obrigatório.',
            'data_nascimento.required' => 'A data de nascimento é obrigatória.',
            'numero_identificacao.required' => 'O número de identificação é obrigatório.',
            'numero_identificacao.unique' => 'Já existe uma pessoa com este número de identificação.',
            'foto.image' => 'A foto deve ser uma imagem válida.',
            'foto.mimes' => 'A foto deve ser um ficheiro PNG, JPG, JPEG ou WEBP.',
            'foto.max' => 'A foto não pode exceder 5MB.',
            'foto.uploaded' => 'A foto não foi enviado: o máximo é 5MB.',
        ], $this->mensagensDeProvinciaMunicipio('provincia_nascimento', 'municipio_nascimento'));
    }
}
