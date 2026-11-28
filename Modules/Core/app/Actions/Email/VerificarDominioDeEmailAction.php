<?php

namespace Modules\Core\Actions\Email;

use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\Comunicacao\Email\Dominio\ClienteDeDominios;
use Modules\Core\Comunicacao\Email\Dominio\ClienteDeDominiosIndisponivel;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Models\DominioEmail;

/** Pergunta ao fornecedor se o DNS está certo. Só `verificado` activa, e só o fornecedor o pode dizer (nunca o formulário). */
class VerificarDominioDeEmailAction
{
    public function __construct(private ClienteDeDominios $cliente, private RegistarAuditoriaTenantAction $auditoria) {}

    /** @throws ValidationException */
    public function executar(DominioEmail $d): DominioEmail
    {
        if ($d->bloqueada_pela_plataforma) {
            throw ValidationException::withMessages(['dominio' => 'A MosiTec bloqueou o envio por domínio próprio desta escola. Contacte a MosiTec.']);
        }

        try {
            $estado = $this->cliente->verificar((string) $d->id_externo);
        } catch (ClienteDeDominiosIndisponivel $e) {
            throw ValidationException::withMessages(['dominio' => $e->getMessage()]);
        }

        $antes = ['estado' => $d->estado->value, 'activo' => $d->activo];

        $d->forceFill([
            'estado' => $estado,
            'activo' => $estado === EstadoConfiguracaoEmail::VERIFICADO,
            'verificado_em' => $estado === EstadoConfiguracaoEmail::VERIFICADO ? now() : null,
            'ultimo_erro' => match ($estado) {
                EstadoConfiguracaoEmail::COM_FALHA => 'O fornecedor não conseguiu verificar os registos DNS. Confirme-os e tente de novo.',
                EstadoConfiguracaoEmail::POR_VERIFICAR => 'Os registos DNS ainda não foram detectados. Pode demorar; tente de novo daqui a pouco.',
                default => null,
            },
        ])->save();

        $this->auditoria->executar(new AuditoriaTenantDTO('dominio_email.verificado', 'dominio_email', $d->id, $antes, ['estado' => $estado->value, 'activo' => $d->activo]));

        return $d;
    }
}
