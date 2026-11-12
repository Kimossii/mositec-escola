<?php

namespace Modules\Core\Tenancy;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * «Existe» limitado à escola corrente: um registo de outra escola conta como inexistente, para a validação não revelar
 * que um identificador existe noutra escola. Para as tabelas com `tenant_id`; a regra `exists:` solta vê todas as escolas.
 */
final class ExisteNaEscola
{
    public static function em(string $tabela, string $coluna = 'id'): Exists
    {
        return Rule::exists($tabela, $coluna)->where('tenant_id', app(TenantContext::class)->id());
    }
}
