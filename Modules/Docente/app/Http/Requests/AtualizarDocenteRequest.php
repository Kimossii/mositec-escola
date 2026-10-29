<?php

namespace Modules\Docente\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;
use Modules\Docente\Models\Docente;

class AtualizarDocenteRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('docente.editar') ?? false;
    }

    public function rules(): array
    {
        /** @var Docente $docente */
        $docente = $this->route('docente');

        return [
            'nome_completo' => ['required', 'string', 'max:255'],
            'numero_identificacao' => [
                'required', 'string', 'max:100',
                Rule::unique('dados_pessoas', 'numero_identificacao')
                    ->where(fn ($query) => $query->where('tenant_id', app(TenantContext::class)->id()))
                    ->ignore($docente->dados_pessoa_id),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'telefone_alternativo' => ['nullable', 'string', 'max:50'],
            'data_nascimento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'integer', Rule::in([0, 1, 2])],
        ];
    }

    public function messages(): array
    {
        return MensagensDeDocente::todas();
    }
}
