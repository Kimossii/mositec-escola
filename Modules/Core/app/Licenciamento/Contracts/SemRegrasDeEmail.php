<?php

namespace Modules\Core\Licenciamento\Contracts;

use Modules\Core\Comunicacao\Email\RegrasDeEmail;

/** Implementação nula de RegrasDeEmailDaLicenca: sem o módulo Licenciamento não há quota de email. */
class SemRegrasDeEmail implements RegrasDeEmailDaLicenca
{
    public function doTenantActual(): RegrasDeEmail
    {
        return new RegrasDeEmail;
    }
}
