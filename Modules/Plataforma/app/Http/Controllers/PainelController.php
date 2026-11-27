<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Services\AuditoriaConsultaService;
use Modules\Plataforma\Services\EnviosEmailConsultaService;
use Modules\Tenant\Services\TenantConsultaService;

/**
 * Página inicial do painel: escolas por estado e actividade recente da Plataforma. Sem contexto de tenant.
 */
class PainelController extends Controller
{
    public function index(TenantConsultaService $escolas, AuditoriaConsultaService $auditoria, EnviosEmailConsultaService $envios)
    {
        return Inertia::render('Plataforma/Painel', [
            'contagem' => $escolas->contagemPorEstado(),
            'emailFalhas' => [
                'total' => $envios->totalDeFalhas(),
                'dias' => 7,
                'recentes' => $envios->falhasRecentes(),
            ],
            'emailInfra' => $envios->infraPorEscola(),
            'actividade' => $auditoria->recentes()->map(fn (RegistoDeAuditoria $registo) => [
                'id' => $registo->id,
                'accao' => $registo->accao,
                'escola' => $registo->codigo_tenant,
                'autor' => $registo->autor?->name,
                'created_at' => $registo->created_at,
            ])->values(),
        ]);
    }
}
