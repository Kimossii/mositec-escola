<?php

namespace Modules\Licenciamento\DTOs;

use Carbon\CarbonImmutable;

/** Sobreposições de uma renovação: tudo o que vier a null herda os direitos da licença renovada. */
final readonly class RenovarLicencaDTO
{
    /**
     * @param  array<string,?int>|null  $limites  só as chaves presentes sobrepõem (null explícito = sem limite)
     * @param  list<string>|null  $modulos
     */
    public function __construct(
        public ?CarbonImmutable $inicio = null,
        public ?CarbonImmutable $expiraEm = null,
        public ?array $limites = null,
        public ?int $toleranciaDias = null,
        public ?int $avisoDias = null,
        public ?array $modulos = null,
    ) {}
}
