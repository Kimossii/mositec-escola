<?php

namespace Modules\Licenciamento\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Aceita a licença colada (`token`) OU um ficheiro de texto (`ficheiro`, pequeno). O conteúdo nunca é registado nem devolvido. */
class InstalarLicencaRequest extends FormRequest
{
    /** Tamanho máximo do ficheiro, em KB (uma licença são poucos KB). */
    public const MAX_KB = 32;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['nullable', 'string', 'max:'.(self::MAX_KB * 1024)],
            'ficheiro' => ['nullable', 'file', 'max:'.self::MAX_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'token.max' => 'O texto da licença é demasiado grande.',
            'ficheiro.file' => 'Carregue um ficheiro de licença válido.',
            'ficheiro.max' => 'O ficheiro da licença é demasiado grande.',
            'ficheiro.uploaded' => 'Não foi possível carregar o ficheiro da licença.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->token() === '') {
                $validator->errors()->add('token', 'Cole o texto da licença ou carregue o ficheiro.');
            }
        }];
    }

    /** O token a instalar: o ficheiro tem prioridade sobre o texto colado. Vazio se nenhum trouxer conteúdo. */
    public function token(): string
    {
        $ficheiro = $this->file('ficheiro');

        if ($ficheiro !== null && $ficheiro->isValid()) {
            $conteudo = trim((string) file_get_contents($ficheiro->getRealPath()));
            if ($conteudo !== '') {
                return $conteudo;
            }
        }

        return trim((string) $this->input('token', ''));
    }
}
