<?php

namespace Modules\Avaliacao\Support\Calculo;

use Modules\Avaliacao\Enums\Decisao;

/**
 * Decisão do aluno a partir das MFD arredondadas (as impressas na pauta). Todas as disciplinas têm de atingir o mínimo,
 * sem compensação pela média global. `null` = ainda não se pode decidir (disciplina sem MFD, ou classe com exame próprio,
 * cuja decisão depende do exame).
 */
class DecisaoFinal
{
    public function __construct(private CalculadoraQuantitativa $calculadora)
    {
    }

    /** @param  array<int, ?string>  $mfdArredondadas */
    public function decidir(array $mfdArredondadas, string $notaMinima, bool $reprovaPorAproveitamento, bool $classeComExame): ?Decisao
    {
        if ($classeComExame || $mfdArredondadas === [] || in_array(null, $mfdArredondadas, true)) {
            return null;
        }

        if (! $reprovaPorAproveitamento) {
            return Decisao::TRANSITA;
        }

        foreach ($mfdArredondadas as $mfd) {
            if (! $this->calculadora->aprovadoNaDisciplina($mfd, $notaMinima)) {
                return Decisao::NAO_TRANSITA;
            }
        }

        return Decisao::TRANSITA;
    }
}
