<?php

namespace Modules\Estabelecimento\Enums;

enum EtapaEnsinoEnum: int
{
    /** Já não se oferece (fora do sistema de ensino considerado); o caso fica só para dados antigos. */
    case CRECHE = 1;
    case PRE_ESCOLAR = 2;
    case PRIMARIO = 3;
    case SECUNDARIO = 4;
    case SUPERIOR = 5;
    /** 7.ª a 9.ª classe: não tem curso (os cursos só existem a partir do Ensino Secundário). */
    case PRIMEIRO_CICLO = 6;

    public function label(): string
    {
        return match ($this) {
            self::CRECHE => 'Creche',
            self::PRE_ESCOLAR => 'Pré-Escolar',
            self::PRIMARIO => 'Ensino Primário',
            self::SECUNDARIO => 'II Ciclo (10.ª a 13.ª classe)',
            self::SUPERIOR => 'Ensino Superior',
            self::PRIMEIRO_CICLO => 'I Ciclo (7.ª a 9.ª classe)',
        };
    }

    public function exigeCurso(): bool
    {
        return match ($this) {
            self::SECUNDARIO, self::SUPERIOR => true,
            default => false,
        };
    }
}
