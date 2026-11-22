<?php

namespace Modules\Licenciamento\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Licenciamento\Assinatura\CodecoDeLicenca;
use Modules\Licenciamento\Assinatura\Exceptions\LicencaInvalida;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\Exceptions\LicencaRecusada;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Services\ChavesPublicas;
use Modules\Licenciamento\Services\CodigoDaInstalacao;
use Modules\Licenciamento\Services\DireitosService;
use SensitiveParameter;

/**
 * Caminho único de instalação de uma licença (SaaS e self-hosted): verifica a assinatura, a ligação à escola/instalação
 * e que não recua no tempo (replay), e só então guarda o token. Reinstalar a mesma licença é inofensivo.
 */
class InstalarLicencaAction
{
    use LogService;

    public function __construct(
        private readonly CodecoDeLicenca $codec,
        private readonly CodigoDaInstalacao $instalacao,
        private readonly DireitosService $direitos,
    ) {}

    /**
     * @throws LicencaRecusada
     */
    public function executar(int $tenantId, string $codigoTenant, #[SensitiveParameter] string $token): DireitosDaLicenca
    {
        return $this->comLog('Instalar licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($tenantId, $codigoTenant, $token));
    }

    /**
     * @throws LicencaRecusada
     */
    private function executarSemRegisto(int $tenantId, string $codigoTenant, string $token): DireitosDaLicenca
    {
        $token = trim($token);

        try {
            $claims = $this->codec->verificar($token, ChavesPublicas::daConfig());
            $direitos = DireitosDaLicenca::deClaims($claims);
        } catch (LicencaInvalida) {
            throw LicencaRecusada::porque('A licença não é válida: a assinatura ou o conteúdo não foram aceites.');
        }

        if ($direitos->escola !== null && $direitos->escola !== $codigoTenant) {
            throw LicencaRecusada::porque('Esta licença pertence a outra escola.');
        }

        if ($direitos->instalacao !== null && $direitos->instalacao !== $this->instalacao->obter()) {
            throw LicencaRecusada::porque('Esta licença pertence a outra instalação.');
        }

        DB::transaction(function () use ($tenantId, $token, $direitos, $claims) {
            $actual = DireitosLocais::query()->where('tenant_id', $tenantId)->lockForUpdate()->first();

            if ($actual !== null) {
                if ($actual->licenca_id === $direitos->id && $actual->emitida_em->equalTo($direitos->emitidaEm)) {
                    return; // a mesma licença: nada a fazer
                }

                if ($direitos->emitidaEm->lte($actual->emitida_em)) {
                    throw LicencaRecusada::porque('Esta licença é mais antiga do que a instalada.');
                }
            }

            $registo = $actual ?? new DireitosLocais;
            $registo->tenant_id = $tenantId;
            $registo->fill([
                'licenca_id' => $direitos->id,
                'emitida_em' => $direitos->emitidaEm,
                'token' => $token,
                'claims' => $claims,
                'revogada_em' => null,
                'instalada_em' => now(),
            ])->save();
        });

        $this->direitos->esquecer();

        return $direitos;
    }
}
