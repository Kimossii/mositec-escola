<?php

namespace Modules\Plataforma\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Core\Models\DominioEmail;
use Modules\Core\Tenancy\ConsultaEntreEscolas;
use Modules\Core\Traits\LogService;
use Modules\Plataforma\DTO\RegistoAuditoriaDTO;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Tenant\Models\Tenant;

/**
 * Levanta o bloqueio posto por ForcarRegressoACentralAction. Não reactiva nada: a escola tem de testar o servidor ou verificar o domínio de novo.
 *
 * @throws \Illuminate\Database\Eloquent\ModelNotFoundException se a escola não tem servidor nem domínio próprio
 */
class LevantarBloqueioDeEmailAction
{
    use LogService;

    public function __construct(private RegistarAuditoriaAction $auditoria) {}

    public function executar(Tenant $tenant, ?SuperAdmin $autor = null): void
    {
        $this->comLog('Levantar bloqueio de email da escola', ['tenant' => $tenant->codigo], function () use ($tenant, $autor) {
            $configuracao = ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->where('tenant_id', $tenant->id)->first();
            $dominio = ConsultaEntreEscolas::de(DominioEmail::class)->where('tenant_id', $tenant->id)->first();

            if ($configuracao === null && $dominio === null) {
                throw (new ModelNotFoundException)->setModel(ConfiguracaoEmail::class);
            }

            if ($configuracao !== null) {
                ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->whereKey($configuracao->id)->update(['bloqueada_pela_plataforma' => false]);
            }

            if ($dominio !== null) {
                ConsultaEntreEscolas::de(DominioEmail::class)->whereKey($dominio->id)->update(['bloqueada_pela_plataforma' => false]);
            }

            $this->auditoria->executar(new RegistoAuditoriaDTO($autor, 'email.bloqueio_levantado', $tenant->codigo));
        });
    }
}
