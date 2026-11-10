<?php

namespace Modules\Avaliacao\Support\Calculo;

use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\TratamentoDeNegativas;

/**
 * Decisão do aluno a partir das notas finais arredondadas (as impressas na pauta: a MFD, ou a MF nas disciplinas com
 * exame). Todas as disciplinas têm de atingir o mínimo, sem compensação pela média global. `null` = ainda não se pode
 * decidir (disciplina sem nota final). Nas classes com exame próprio (6.ª e 9.ª) os termos são Apto/Não apto.
 */
class DecisaoFinal
{
    public function __construct(private CalculadoraQuantitativa $calculadora)
    {
    }

    /** @param  array<int, ?string>  $notasFinais */
    public function decidir(array $notasFinais, string $notaMinima, TratamentoDeNegativas $tratamento, bool $termosApto): ?Decisao
    {
        if ($notasFinais === [] || in_array(null, $notasFinais, true)) {
            return null;
        }

        [$positiva, $negativa] = $termosApto ? [Decisao::APTO, Decisao::NAO_APTO] : [Decisao::TRANSITA, Decisao::NAO_TRANSITA];

        // Só «reprova» decide pelas negativas. Nos outros tratamentos o aluno segue, e a negativa fica marcada na pauta.
        if ($tratamento !== TratamentoDeNegativas::REPROVA) {
            return $positiva;
        }

        foreach ($notasFinais as $nota) {
            if (! $this->calculadora->aprovadoNaDisciplina($nota, $notaMinima)) {
                return $negativa;
            }
        }

        return $positiva;
    }
}
