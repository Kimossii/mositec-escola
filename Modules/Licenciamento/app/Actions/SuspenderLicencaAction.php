<?php

namespace Modules\Licenciamento\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Exceptions\DadosDeLicencaInvalidos;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Services\CicloDeVidaDaLicenca;

/**
 * Activa -> Suspensa. Se a escola é desta instalação (SaaS), revoga também a licença instalada: o bloqueio é IMEDIATO.
 *
 * LIMITE: num token offline/self-hosted só muda o registo do emissor; o token já entregue continua a valer na
 * instalação do cliente até expirar (não há canal de revogação sem a sincronização da Fase 4). Não precisa de chave.
 */
class SuspenderLicencaAction
{
    use LogService;

    public function __construct(private readonly CicloDeVidaDaLicenca $ciclo) {}

    public function executar(LicencaEmitida $licenca, string $motivo, ?int $autorId): LicencaEmitida
    {
        return $this->comLog('Suspender licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($licenca, $motivo, $autorId));
    }

    /** @throws DadosDeLicencaInvalidos */
    private function executarSemRegisto(LicencaEmitida $licenca, string $motivo, ?int $autorId): LicencaEmitida
    {
        $motivo = $this->ciclo->motivo($motivo);

        return DB::transaction(function () use ($licenca, $motivo, $autorId) {
            $actual = $this->ciclo->bloquear($licenca);
            $this->ciclo->exigirEstado($actual, [EstadoComercial::ACTIVA], 'suspender');

            $actual->forceFill(['estado_comercial' => EstadoComercial::SUSPENSA])->save();
            $this->ciclo->revogarLocalmente($actual);
            $this->ciclo->registar($actual, 'suspensa', $motivo, $autorId);

            $licenca->setRawAttributes($actual->getAttributes(), true);

            return $licenca;
        });
    }
}
