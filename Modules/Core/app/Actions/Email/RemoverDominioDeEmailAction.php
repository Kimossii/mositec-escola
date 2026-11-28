<?php

namespace Modules\Core\Actions\Email;

use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Models\DominioEmail;

/** A escola deixa de usar o domínio próprio e volta a enviar pela central. */
class RemoverDominioDeEmailAction
{
    public function __construct(private RegistarAuditoriaTenantAction $auditoria) {}

    /** @throws ValidationException Bloqueado: remover e voltar a registar contornaria o bloqueio. */
    public function executar(DominioEmail $d): void
    {
        if ($d->bloqueada_pela_plataforma) {
            throw ValidationException::withMessages(['dominio' => 'A MosiTec bloqueou o envio por domínio próprio desta escola. Contacte a MosiTec.']);
        }

        $this->auditoria->executar(new AuditoriaTenantDTO('dominio_email.removido', 'dominio_email', $d->id, ['dominio' => $d->dominio, 'activo' => $d->activo], []));

        $d->delete();
    }
}
