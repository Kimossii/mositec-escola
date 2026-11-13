<?php

namespace Modules\Turma\Support;

use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;

/**
 * As classes padrão do ensino geral angolano (Creche a 13.ª). A escola recebe as de cada etapa quando a declara nos dados do
 * estabelecimento. O código é só um identificador interno: as regras de avaliação por defeito ligam-se por ele, por isso a
 * escola nunca precisa de o escrever. Apenas a Creche até à 9.ª classe se criam automaticamente; a 10.ª à 13.ª ficam fora.
 */
class NiveisAcademicosPadrao
{
    /** @return list<array{codigo: string, nome: string, ordem: int, etapa_ensino: EtapaEnsinoEnum, automatico: bool}> */
    public static function todos(): array
    {
        $classe = fn (string $codigo, string $nome, int $ordem, EtapaEnsinoEnum $etapa, bool $automatico = true) => compact('codigo', 'nome', 'ordem') + ['etapa_ensino' => $etapa, 'automatico' => $automatico];

        return [
            $classe('CRECHE_I', 'Creche I', 1, EtapaEnsinoEnum::CRECHE),
            $classe('CRECHE_II', 'Creche II', 2, EtapaEnsinoEnum::CRECHE),
            $classe('PRE_I', 'Iniciação', 3, EtapaEnsinoEnum::PRE_ESCOLAR),
            $classe('PRE_II', 'Pré-Escolar I', 4, EtapaEnsinoEnum::PRE_ESCOLAR),
            $classe('PRE_III', 'Pré-Escolar II', 5, EtapaEnsinoEnum::PRE_ESCOLAR),
            $classe('PRI_1', '1.ª Classe', 6, EtapaEnsinoEnum::PRIMARIO),
            $classe('PRI_2', '2.ª Classe', 7, EtapaEnsinoEnum::PRIMARIO),
            $classe('PRI_3', '3.ª Classe', 8, EtapaEnsinoEnum::PRIMARIO),
            $classe('PRI_4', '4.ª Classe', 9, EtapaEnsinoEnum::PRIMARIO),
            $classe('PRI_5', '5.ª Classe', 10, EtapaEnsinoEnum::PRIMARIO),
            $classe('PRI_6', '6.ª Classe', 11, EtapaEnsinoEnum::PRIMARIO),
            $classe('SEC_7', '7.ª Classe', 12, EtapaEnsinoEnum::PRIMEIRO_CICLO),
            $classe('SEC_8', '8.ª Classe', 13, EtapaEnsinoEnum::PRIMEIRO_CICLO),
            $classe('SEC_9', '9.ª Classe', 14, EtapaEnsinoEnum::PRIMEIRO_CICLO),
            $classe('SEC_10', '10.ª Classe', 15, EtapaEnsinoEnum::SECUNDARIO, automatico: false),
            $classe('SEC_11', '11.ª Classe', 16, EtapaEnsinoEnum::SECUNDARIO, automatico: false),
            $classe('SEC_12', '12.ª Classe', 17, EtapaEnsinoEnum::SECUNDARIO, automatico: false),
            $classe('SEC_13', '13.ª Classe', 18, EtapaEnsinoEnum::SECUNDARIO, automatico: false),
        ];
    }

    /** As que se criam sozinhas quando a escola declara a etapa. */
    public static function daEtapa(EtapaEnsinoEnum $etapa): array
    {
        return array_values(array_filter(self::todos(), fn (array $c) => $c['automatico'] && $c['etapa_ensino'] === $etapa));
    }
}
