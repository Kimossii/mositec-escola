<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Enums\TratamentoDeNegativas;

/** Uma regra de avaliação numérica de um ano lectivo e as classes que a seguem. */
readonly class RegraAvaliacaoDTO
{
    /**
     * @param  list<int>  $nivel_ids  as classes ligadas a esta regra (saem de qualquer outra regra do ano)
     * @param  list<int>  $disciplinas_chave  negativa a uma delas = reprovação directa, sem recurso
     * @param  ?bool  $com_exame  nulo = o que as classes têm por defeito
     * @param  ?bool  $com_recurso  nulo = o que as classes têm por defeito
     * @param  ?int  $regra_id  nulo = a regra que a primeira classe já segue, ou uma regra nova se ela não segue nenhuma
     * @param  bool  $nova  cria sempre uma regra nova, mesmo que as classes já sigam outra
     */
    public function __construct(
        public int $ano_lectivo_id,
        public array $nivel_ids,
        public int $escala_maxima,
        public string $nota_minima,
        public string $peso_mac = '50',
        public string $peso_pt = '50',
        public ?string $peso_mt1 = null,
        public ?string $peso_mt2 = null,
        public ?string $peso_mt3 = null,
        public int $casas_decimais = 2,
        public ModoArredondamento $modo_arredondamento = ModoArredondamento::HALF_UP,
        public TratamentoDeNegativas $tratamento_negativas = TratamentoDeNegativas::REPROVA,
        public string $peso_mfd_exame = '40',
        public string $peso_exame = '60',
        public ?int $recurso_max_disciplinas = null,
        public array $disciplinas_chave = [],
        public ?int $regra_id = null,
        public string $nome = '',
        public ?bool $com_exame = null,
        public ?bool $com_recurso = null,
        /** o exame e o recurso partem da nota final arredondada (a que aparece na pauta) em vez da exacta */
        public bool $exame_usa_mfd_arredondada = true,
        public bool $nova = false,
        /** como a escola chama à prova trimestral nos documentos (PT, NPT…) */
        public string $sigla_prova = 'PT',
    ) {
    }
}
