<?php

namespace Modules\Core\Services;

use Modules\Core\Models\DominioEmail;

/** Leitura do domínio de envio da escola corrente para o ecrã. */
class DominioEmailConsultaService
{
    public function actual(): ?DominioEmail
    {
        return DominioEmail::query()->first();
    }

    /** @return array<string, mixed>|null */
    public function paraEcra(): ?array
    {
        $d = $this->actual();

        return $d === null ? null : [
            'dominio' => $d->dominio,
            'endereco' => $d->endereco(),
            'remetente_nome' => $d->remetente_nome,
            'remetente_local' => $d->remetente_local,
            'registos' => $d->registos ?? [],
            'estado' => $d->estado->value,
            'estado_descricao' => $d->estado_descricao,
            'activo' => $d->activo,
            'bloqueada_pela_plataforma' => $d->bloqueada_pela_plataforma,
            'verificado_em' => $d->verificado_em?->format('Y-m-d H:i:s'),
            'ultimo_erro' => $d->ultimo_erro,
        ];
    }
}
