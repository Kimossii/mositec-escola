<?php

namespace Modules\Estabelecimento\Http\Requests;

use App\Http\Requests\BaseRequest;

class AtualizarLogotipoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-estabelecimento') ?? false;
    }

    public function rules(): array
    {
        return [
            'logotipo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'logotipo.required' => 'Selecione um ficheiro de logótipo.',
            'logotipo.image' => 'O logótipo deve ser uma imagem válida.',
            'logotipo.mimes' => 'O logótipo deve ser um ficheiro PNG, JPG, JPEG ou WEBP.',
            'logotipo.max' => 'O logótipo não pode exceder 2MB.',
        ];
    }
}
