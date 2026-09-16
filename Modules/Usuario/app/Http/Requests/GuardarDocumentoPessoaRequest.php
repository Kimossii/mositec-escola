<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class GuardarDocumentoPessoaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documento-pessoa.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'tipo_documento_id' => ['required', 'integer', Rule::exists('tipos_documentos', 'id')],
            'numero_documento' => ['nullable', 'string', 'max:100'],
            'data_emissao' => ['nullable', 'date'],
            'data_validade' => ['nullable', 'date', 'after_or_equal:data_emissao'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'ficheiro' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_documento_id.required' => 'Selecione o tipo de documento.',
            'tipo_documento_id.exists' => 'Tipo de documento inválido.',
            'data_validade.after_or_equal' => 'A data de validade não pode ser anterior à data de emissão.',
            'ficheiro.required' => 'Selecione um ficheiro.',
            'ficheiro.file' => 'O ficheiro enviado é inválido.',
            'ficheiro.max' => 'O ficheiro não pode exceder 2MB.',
            'ficheiro.mimes' => 'O ficheiro deve ser PDF, JPG, JPEG ou PNG.',
        ];
    }
}
