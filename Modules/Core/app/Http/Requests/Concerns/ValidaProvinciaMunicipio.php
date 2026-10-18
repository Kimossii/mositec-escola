<?php

namespace Modules\Core\Http\Requests\Concerns;

use Closure;
use Illuminate\Validation\Rule;
use Modules\Core\Enums\ProvinciaMunicipioEnum;

/**
 * Validação partilhada de província/município (Estabelecimento e dados do aluno):
 * a província tem de ser um valor do enum e o município tem de pertencer a essa
 * província. Ambos continuam anuláveis; sem província não há município válido.
 */
trait ValidaProvinciaMunicipio
{
    /** @return array<string, mixed> */
    protected function regrasDeProvinciaMunicipio(string $campoProvincia, string $campoMunicipio): array
    {
        return [
            $campoProvincia => ['nullable', 'string', 'max:100', Rule::in(ProvinciaMunicipioEnum::values())],
            $campoMunicipio => [
                'nullable',
                'string',
                'max:100',
                function (string $atributo, mixed $valor, Closure $falhar) use ($campoProvincia) {
                    $provincia = $this->input($campoProvincia);

                    if ($provincia === null || $provincia === '') {
                        $falhar('Indique a província antes de escolher o município.');

                        return;
                    }

                    $enum = ProvinciaMunicipioEnum::tryFrom((string) $provincia);

                    if ($enum === null || ! in_array($valor, $enum->municipios(), true)) {
                        $falhar('O município indicado não pertence à província escolhida.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    protected function mensagensDeProvinciaMunicipio(string $campoProvincia, string $campoMunicipio): array
    {
        return [
            $campoProvincia . '.in' => 'A província indicada é inválida.',
            $campoMunicipio . '.max' => 'O município não pode ultrapassar 100 caracteres.',
        ];
    }
}
