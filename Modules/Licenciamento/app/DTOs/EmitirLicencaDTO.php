<?php

namespace Modules\Licenciamento\DTOs;

use Carbon\CarbonImmutable;
use Modules\Licenciamento\Enums\Modalidade;

/** Pedido de emissão. Tudo o que não é o plano é sobreposição por licença (null = herdar do plano). */
final readonly class EmitirLicencaDTO
{
    /**
     * @param  array<string,?int>|null  $limites  só as chaves presentes sobrepõem (null explícito = sem limite)
     * @param  list<string>|null  $modulos
     */
    public function __construct(
        public int $planoId,
        public ?string $escola = null,
        public ?string $instalacao = null,
        public ?CarbonImmutable $inicio = null,
        public ?CarbonImmutable $expiraEm = null,
        public ?array $limites = null,
        public ?int $toleranciaDias = null,
        public ?int $avisoDias = null,
        public ?array $modulos = null,
        public ?Modalidade $modalidade = null,
        public ?bool $ligacaoObrigatoria = null,
        /** Garante `iat` estritamente posterior (renovações/extensões): se o relógio não avançou, usa este instante + 1 s. */
        public ?CarbonImmutable $emitidaDepoisDe = null,
        /** Id (jti) da licença que esta substitui; só viaja no evento `emitida`. */
        public ?string $substituiLicencaId = null,
        /** Motivo livre registado no evento `emitida` (ex.: «transição»); sem dados sensíveis. */
        public ?string $motivo = null,
    ) {}
}
