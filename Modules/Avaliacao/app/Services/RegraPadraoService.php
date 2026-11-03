<?php

namespace Modules\Avaliacao\Services;

/**
 * Valores iniciais da regra por classe (códigos de `NivelAcademico`): 1.ª–6.ª em 0–10 com mínimo 5, 7.ª–9.ª em 0–20
 * com mínimo 10. A 1.ª e a 2.ª não reprovam por aproveitamento. Pré-escolar e creche não têm regra quantitativa.
 */
class RegraPadraoService
{
    /** @return array{escala_maxima: int, nota_minima: string, reprova_por_aproveitamento: bool}|null */
    public function paraNivel(string $codigoNivel): ?array
    {
        return match ($codigoNivel) {
            'PRI_1', 'PRI_2' => ['escala_maxima' => 10, 'nota_minima' => '5', 'reprova_por_aproveitamento' => false],
            'PRI_3', 'PRI_4', 'PRI_5', 'PRI_6' => ['escala_maxima' => 10, 'nota_minima' => '5', 'reprova_por_aproveitamento' => true],
            'SEC_7', 'SEC_8', 'SEC_9' => ['escala_maxima' => 20, 'nota_minima' => '10', 'reprova_por_aproveitamento' => true],
            default => null,
        };
    }
}
