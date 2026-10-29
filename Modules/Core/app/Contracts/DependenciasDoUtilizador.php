<?php

namespace Modules\Core\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Implementada por módulos que guardam registos ligados a um utilizador (ex.: atribuições docentes) sem que o
 * Usuario os importe (recebe-se o `Authenticatable`: o Core não importa o model do Usuario). Devolve o motivo em português quando a eliminação deve ser bloqueada, ou null quando está livre.
 * Registo no container: $this->app->tag([Classe::class], DependenciasRegistadasDoUtilizador::ETIQUETA).
 */
interface DependenciasDoUtilizador
{
    /** Motivo para bloquear a eliminação do utilizador, ou null. */
    public function bloqueiaEliminacao(Authenticatable $utilizador): ?string;
}
