<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Http\Controllers\Concerns\AuditaSemFalhar;
use Modules\Plataforma\Http\Requests\AdicionarDominioRequest;
use Modules\Tenant\Actions\AdicionarDominioAction;
use Modules\Tenant\Actions\RemoverDominioAction;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Models\Tenant;

/**
 * Domínios de uma escola. Chama as Actions do módulo Tenant (que decidem formato, reservados, host
 * central, duplicados, tipo, principal e estado) e audita o sucesso. Sem contexto de tenant.
 */
class DominioController extends Controller
{
    use AuditaSemFalhar;

    public function store(AdicionarDominioRequest $request, Tenant $tenant, AdicionarDominioAction $adicionar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $dominio = $adicionar->executar($tenant, $request->validated('dominio'));
        } catch (DadosDeTenantInvalidos $e) {
            return $this->voltar($tenant)->withErrors($e->erros);
        } catch (OperacaoDeTenantRecusada $e) {
            return $this->voltar($tenant)->withErrors(['geral' => $e->getMessage()]);
        }

        $this->auditar($auditoria, $request, 'dominio.adicionado', $tenant->codigo, ['dominio' => $dominio->dominio]);

        return $this->voltar($tenant)->with('success', "Domínio {$dominio->dominio} adicionado.");
    }

    /** `{dominio}` é o nome do host (sem binding de model): a Action decide se pertence à escola e se é removível. */
    public function destroy(Request $request, Tenant $tenant, string $dominio, RemoverDominioAction $remover, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $remover->executar($tenant, $dominio);
        } catch (OperacaoDeTenantRecusada $e) {
            return $this->voltar($tenant)->withErrors(['geral' => $e->getMessage()]);
        }

        $host = NormalizadorHost::normalizar($dominio);
        $this->auditar($auditoria, $request, 'dominio.removido', $tenant->codigo, ['dominio' => $host]);

        return $this->voltar($tenant)->with('success', "Domínio {$host} removido.");
    }

    private function voltar(Tenant $tenant): RedirectResponse
    {
        return redirect()->route('plataforma.escolas.show', $tenant->codigo);
    }
}
