<?php

namespace Modules\Autenticacao\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarFotoRequest extends FormRequest
{
    /** Auto-serviço: cada utilizador só altera a sua própria foto. */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['foto' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120']];
    }

    public function messages(): array
    {
        return [
            'foto.required' => 'Escolha uma foto.',
            'foto.image' => 'A foto deve ser uma imagem válida.',
            'foto.mimes' => 'A foto deve ser um ficheiro PNG, JPG, JPEG ou WEBP.',
            'foto.max' => 'A foto não pode exceder 5MB.',
            'foto.uploaded' => 'Não foi possível enviar a foto (máximo 5MB).',
        ];
    }
}
