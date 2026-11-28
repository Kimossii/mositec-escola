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
 * O Super Admin manda uma escola de volta à infra central de email (ex.: servidor ou domínio próprio a abusar ou avariado).
 * Desliga `activo` e bloqueia, no servidor SMTP e no domínio que a escola tiver: a escola não pode testar, verificar, remover nem reactivar até a Plataforma levantar o bloqueio
 * (LevantarBloqueioDeEmailAction). A configuração e a verificação ficam intactas.
 * Corre sem contexto de escola, por isso procura a configuração sempre pelo `tenant_id` pedido.
 *
 * @throws \Illuminate\Database\Eloquent\ModelNotFoundException se a escola não tem servidor nem domínio próprio
 */
class ForcarRegressoACentralAction
{
    use LogService;

    public function __construct(private RegistarAuditoriaAction $auditoria) {}

    public function executar(Tenant $tenant, ?SuperAdmin $autor = null): void
    {
        $this->comLog('Forçar regresso do email da escola à central', ['tenant' => $tenant->codigo], function () use ($tenant, $autor) {
            $configuracao = ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->where('tenant_id', $tenant->id)->first();
            $dominio = ConsultaEntreEscolas::de(DominioEmail::class)->where('tenant_id', $tenant->id)->first();

            if ($configuracao === null && $dominio === null) {
                throw (new ModelNotFoundException)->setModel(ConfiguracaoEmail::class);
            }

            $estavaActiva = (bool) ($configuracao?->activo || $dominio?->activo);

            // Directo por query: um save() do Model exigiria o contexto desta escola (PertenceAoTenant).
            if ($configuracao !== null) {
                ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->whereKey($configuracao->id)->update(['activo' => false, 'bloqueada_pela_plataforma' => true]);
            }

            if ($dominio !== null) {
                ConsultaEntreEscolas::de(DominioEmail::class)->whereKey($dominio->id)->update(['activo' => false, 'bloqueada_pela_plataforma' => true]);
            }

            $this->auditoria->executar(new RegistoAuditoriaDTO($autor, 'email.regresso_central_forcado', $tenant->codigo, ['estava_activa' => $estavaActiva, 'servidor_proprio' => $configuracao !== null, 'dominio' => $dominio !== null]));
        });
    }
}
