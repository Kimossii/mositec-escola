<?php

namespace Modules\Financeiro\Exceptions;

use DomainException;

/**
 * A geração de propinas está desactivada pelo interruptor de segurança `financeiro.propinas.geracao_activa`
 * (R-F2.1: até estar concluída a protecção das matrículas, F3).
 */
class GeracaoDePropinasDesactivada extends DomainException
{
    public const MENSAGEM = 'A geração de propinas está desactivada até estar concluída a protecção das matrículas (F3).';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }

    public static function activa(): bool
    {
        return (bool) config('financeiro.propinas.geracao_activa', false);
    }
}
