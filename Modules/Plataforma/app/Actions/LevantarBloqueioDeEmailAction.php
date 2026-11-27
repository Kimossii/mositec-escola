<?php

namespace Modules\Plataforma\Actions;

use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Core\Tenancy\ConsultaEntreEscolas;
use Modules\Core\Traits\LogService;
use Modules\Plataforma\DTO\RegistoAuditoriaDTO;
use Modules\Plataforma\Models\SuperAdmin;
use Modules\Tenant\Models\Tenant;

/**
 * Levanta o bloqueio posto por ForcarRegressoACentralAction. Não reactiva o servidor: a escola tem de o testar de novo.
 *
 * @throws \Illuminate\Database\Eloquent\ModelNotFoundException se a escola não tem configuração de email
 */
class LevantarBloqueioDeEmailAction
{
    use LogService;

    public function __construct(private RegistarAuditoriaAction $auditoria) {}

    public function executar(Tenant $tenant, ?SuperAdmin $autor = null): void
    {
        $this->comLog('Levantar bloqueio de email da escola', ['tenant' => $tenant->codigo], function () use ($tenant, $autor) {
            $configuracao = ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->where('tenant_id', $tenant->id)->firstOrFail();

            ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->whereKey($configuracao->id)->update(['bloqueada_pela_plataforma' => false]);

            $this->auditoria->executar(new RegistoAuditoriaDTO($autor, 'email.bloqueio_levantado', $tenant->codigo));
        });
    }
}
