<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;

class AnexarComprovativoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pagamento.criar') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'comprovativo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'comprovativo.required' => 'Anexe o comprovativo de pagamento.',
            'comprovativo.file' => 'O comprovativo tem de ser um ficheiro.',
            'comprovativo.mimes' => 'O comprovativo tem de ser um PDF, JPG, JPEG, PNG ou WEBP.',
            'comprovativo.max' => 'O comprovativo não pode exceder 5 MB.',
        ];
    }
}
