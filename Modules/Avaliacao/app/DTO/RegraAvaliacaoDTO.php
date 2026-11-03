<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\ModoArredondamento;

readonly class RegraAvaliacaoDTO
{
    public function __construct(
        public int $ano_lectivo_id,
        public int $nivel_academico_id,
        public int $escala_maxima,
        public string $nota_minima,
        public string $peso_mac = '50',
        public string $peso_pt = '50',
        public ?string $peso_mt1 = null,
        public ?string $peso_mt2 = null,
        public ?string $peso_mt3 = null,
        public int $casas_decimais = 2,
        public ModoArredondamento $modo_arredondamento = ModoArredondamento::HALF_UP,
        public bool $reprova_por_aproveitamento = true,
    ) {
    }
}
