<?php

namespace Modules\Core\Tenancy\Provisioning;

use Illuminate\Support\Str;

/**
 * Origem da senha temporária do administrador inicial. Isolada para poder ser substituída nos testes.
 */
class GeradorSenhaTemporaria
{
    public function gerar(): string
    {
        return Str::password(14);
    }
}
