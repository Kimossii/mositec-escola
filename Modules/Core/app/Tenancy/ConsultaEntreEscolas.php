<?php

namespace Modules\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Único ponto, fora do runtime de tenancy, que levanta o isolamento por escola para LER ou actualizar um registo de uma escola
 * indicada, sem contexto de escola (o painel da Plataforma). Quem o usa filtra sempre por `tenant_id` ou agrega só metadados;
 * nunca devolve conteúdo de negócio a quem não é Super Admin.
 */
final class ConsultaEntreEscolas
{
    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $modelo
     * @return Builder<T>
     */
    public static function de(string $modelo): Builder
    {
        return $modelo::withoutGlobalScopes();
    }
}
