<?php

namespace Modules\Core\Actions\Email;

use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Models\ConfiguracaoEmail;

/** A escola volta a enviar pela central. Mantém a configuração (e a verificação) para poder reactivar com novo teste. */
class DesactivarConfiguracaoEmailAction
{
    public function executar(ConfiguracaoEmail $c): void
    {
        $c->forceFill(['activo' => false])->save();

        app(RegistarAuditoriaTenantAction::class)->executar(new AuditoriaTenantDTO(
            'configuracao_email.desactivada',
            'configuracao_email',
            $c->id,
            ['activo' => true],
            ['activo' => false],
        ));
    }
}
