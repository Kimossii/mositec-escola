<?php

namespace Modules\AnoLectivo\Contracts;

use Modules\AnoLectivo\Models\AnoLectivo;

/**
 * Implementada por módulos que dependem de um ano lectivo (ex.: planos de propina ancorados
 * no mês de data_inicio) sem que o AnoLectivo os importe. Devolve o motivo em português
 * quando a operação deve ser bloqueada, ou null quando está livre.
 * Registo no container: $this->app->tag([Classe::class], DependenciasRegistadasDoAnoLectivo::ETIQUETA).
 */
interface DependenciasDoAnoLectivo
{
    /** Motivo para bloquear a mudança do mês/ano de data_inicio, ou null. */
    public function bloqueiaAlteracaoDeInicio(AnoLectivo $anoLectivo): ?string;

    /** Motivo para bloquear a eliminação do ano lectivo, ou null. */
    public function bloqueiaEliminacao(AnoLectivo $anoLectivo): ?string;
}
