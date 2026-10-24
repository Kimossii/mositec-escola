<?php

namespace Modules\Core\Services;

use Modules\Core\Enums\ProvinciaMunicipioEnum;

/**
 * Províncias e municípios de Angola para os formulários (Estabelecimento e dados do aluno).
 * A fonte é o enum; nunca se escreve.
 */
class ProvinciaMunicipioService
{
    /** @return list<array{value: string, label: string}> */
    public function provincias(): array
    {
        return array_map(
            fn (ProvinciaMunicipioEnum $provincia) => ['value' => $provincia->value, 'label' => $provincia->value],
            ProvinciaMunicipioEnum::cases(),
        );
    }

    /** @return array<string, list<string>> */
    public function municipiosPorProvincia(): array
    {
        return ProvinciaMunicipioEnum::allMunicipios();
    }
}
