<?php

namespace Modules\Permissao\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Permissao\Models\Role;

class AtribuirPerfilRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('autorizacao.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            // Pelo modelo, que só vê os perfis da escola: um perfil de outra escola é «não existe», sem distinguir.
            'role_id' => ['required', 'integer', function (string $atributo, mixed $valor, \Closure $falhar) {
                if (! Role::query()->whereKey($valor)->exists()) {
                    $falhar('O perfil indicado não existe.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.required' => 'Escolha o perfil.',
            'role_id.integer' => 'O perfil indicado é inválido.',
        ];
    }
}
