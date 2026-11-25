<?php

namespace Modules\Plataforma\Actions;

use Modules\Core\Traits\LogService;
use Modules\Plataforma\Models\SuperAdmin;

/** Desactiva o 2FA (ou cancela uma activação por confirmar): limpa segredo, códigos e confirmação. */
class DesactivarDoisFactoresSuperAdminAction
{
    use LogService;

    public function executar(SuperAdmin $admin): void
    {
        $this->comLog('Desactivar 2FA do super admin', ['super_admin_id' => $admin->id], function () use ($admin) {
            $admin->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        });
    }
}
