<?php

namespace Modules\Core\Licenciamento\Contracts;

use Modules\Core\Comunicacao\Email\RegrasDeEmail;

/** Pergunta única «que regras de email tem a escola actual?», para quem não pode importar o Licenciamento. */
interface RegrasDeEmailDaLicenca
{
    public function doTenantActual(): RegrasDeEmail;
}
