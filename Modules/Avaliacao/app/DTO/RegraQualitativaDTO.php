<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\Progressao;

/** Uma regra qualitativa (Creche, Pré-Escolar) de um ano lectivo: a escala, a progressão e as classes que a seguem. */
readonly class RegraQualitativaDTO
{
    /**
     * @param  list<int>  $nivel_ids  as classes ligadas a esta regra (saem de qualquer outra regra do ano)
     * @param  ?int  $regra_id  nulo = a regra que a primeira classe já segue, ou uma regra nova se ela não segue nenhuma
     * @param  bool  $nova  cria sempre uma regra nova, mesmo que as classes já sigam outra
     */
    public function __construct(
        public int $ano_lectivo_id,
        public array $nivel_ids,
        public ?int $escala_id,
        public Progressao $progressao = Progressao::AUTOMATICA,
        public ?int $regra_id = null,
        public string $nome = '',
        public bool $nova = false,
    ) {
    }
}
