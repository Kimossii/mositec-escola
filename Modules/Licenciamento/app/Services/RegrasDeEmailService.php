<?php

namespace Modules\Licenciamento\Services;

use Modules\Core\Comunicacao\Email\RegrasDeEmail;
use Modules\Core\Licenciamento\Contracts\RegrasDeEmailDaLicenca;

/** Implementa o contrato do Core sobre os direitos da escola actual (flag inactiva ou sem licença: sem limite). */
class RegrasDeEmailService implements RegrasDeEmailDaLicenca
{
    public function __construct(private readonly DireitosService $direitos) {}

    public function doTenantActual(): RegrasDeEmail
    {
        if (! config('licenciamento.activo')) {
            return new RegrasDeEmail;
        }

        $direitos = $this->direitos->doTenantActual();

        return $direitos === null
            ? new RegrasDeEmail
            : new RegrasDeEmail($direitos->limiteEmailsMes(), $direitos->emailsAvisoPct, $direitos->emailsBloqueia);
    }
}
