<?php

namespace Modules\Plataforma\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\DTO\RegistoAuditoriaDTO;
use Modules\Plataforma\Models\SuperAdmin;
use Throwable;

/**
 * Regista a auditoria de uma operação que JÁ teve sucesso. Se a auditoria falhar, a falha vai para
 * os logs (`report`) e o pedido continua: a operação não se desfaz e o operador não perde o resultado.
 */
trait AuditaSemFalhar
{
    /**
     * @param  array<string, mixed>  $detalhe
     */
    private function auditar(RegistarAuditoriaAction $auditoria, Request $request, string $accao, ?string $codigoTenant, array $detalhe = [], ?SuperAdmin $autor = null): void
    {
        try {
            $auditoria->executar(new RegistoAuditoriaDTO($autor ?? $request->user('plataforma'), $accao, $codigoTenant, $detalhe));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
