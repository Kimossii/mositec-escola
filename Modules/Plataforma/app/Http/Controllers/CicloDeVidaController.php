<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Http\Controllers\Concerns\AuditaSemFalhar;
use Modules\Plataforma\Http\Requests\EncerrarEscolaRequest;
use Modules\Plataforma\Http\Requests\SuspenderEscolaRequest;
use Modules\Tenant\Actions\EncerrarTenantAction;
use Modules\Tenant\Actions\ReactivarTenantAction;
use Modules\Tenant\Actions\RevogarAcessosAposSuspensaoAction;
use Modules\Tenant\Actions\RevogarAcessosDeEscolaSuspensaAction;
use Modules\Tenant\Actions\SuspenderTenantAction;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\OperacaoDeTenantRecusada;
use Modules\Tenant\Models\Tenant;
use Throwable;

/**
 * Suspender, reactivar e encerrar escolas. Cada pedido chama a Action do módulo Tenant e audita o
 * sucesso; as recusas mostram a mensagem da Action. Nunca abre contexto de tenant: a revogação de
 * acessos abre-o dentro da sua Action.
 */
class CicloDeVidaController extends Controller
{
    use AuditaSemFalhar;

    public function suspender(
        SuspenderEscolaRequest $request,
        Tenant $tenant,
        SuspenderTenantAction $suspender,
        RevogarAcessosAposSuspensaoAction $revogar,
        RegistarAuditoriaAction $auditoria,
    ): RedirectResponse {
        $motivo = $request->validated('motivo');
        $revogarAcessos = $request->boolean('revogar_acessos');

        try {
            $suspender->executar($tenant, $motivo);
        } catch (DadosDeTenantInvalidos $e) {
            return $this->voltar($tenant)->withErrors($e->erros);
        } catch (OperacaoDeTenantRecusada $e) {
            return $this->voltar($tenant)->withErrors(['geral' => $e->getMessage()]);
        }

        // A revogação só corre depois de a suspensão ter tido sucesso.
        $detalhe = ['motivo' => $motivo, 'revogar_acessos' => $revogarAcessos];
        $mensagem = 'Escola suspensa.';
        $falhouARevogacao = false;

        if ($revogarAcessos) {
            try {
                $revogados = $revogar->executar($tenant);
                $detalhe += ['sessoes_revogadas' => $revogados['sessoes'], 'tokens_revogados' => $revogados['tokens']];
                $mensagem .= " {$revogados['sessoes']} sessão(ões) e {$revogados['tokens']} token(s) revogado(s).";
            } catch (Throwable $e) {
                // A escola já está suspensa: não se perde o resultado nem o rasto. O detalhe técnico vai para os logs.
                report($e);
                $detalhe['revogacao_falhou'] = true;
                $falhouARevogacao = true;
            }
        }

        $this->auditar($auditoria, $request, 'escola.suspensa', $tenant->codigo, $detalhe);

        if ($falhouARevogacao) {
            return $this->voltar($tenant)->withErrors(['geral' => 'A escola ficou suspensa, mas não foi possível revogar as sessões e os tokens. Tente de novo pelo comando mosi:tenant:suspend --revogar-sessoes ou contacte a equipa técnica.']);
        }

        return $this->voltar($tenant)->with('success', $mensagem);
    }

    public function reactivar(Request $request, Tenant $tenant, ReactivarTenantAction $reactivar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $reactivada = $reactivar->executar($tenant);
        } catch (OperacaoDeTenantRecusada $e) {
            return $this->voltar($tenant)->withErrors(['geral' => $e->getMessage()]);
        }

        $this->auditar($auditoria, $request, 'escola.reactivada', $tenant->codigo, ['reactivado_em' => $reactivada->reactivado_em->toIso8601String()]);

        return $this->voltar($tenant)->with('success', 'Escola reactivada.');
    }

    public function encerrar(EncerrarEscolaRequest $request, Tenant $tenant, EncerrarTenantAction $encerrar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $encerrada = $encerrar->executar($tenant, $request->validated('motivo'));
        } catch (DadosDeTenantInvalidos $e) {
            return $this->voltar($tenant)->withErrors($e->erros);
        } catch (OperacaoDeTenantRecusada $e) {
            return $this->voltar($tenant)->withErrors(['geral' => $e->getMessage()]);
        }

        // O motivo auditado é o que a Action gravou (aparado; nulo se vazio).
        $this->auditar($auditoria, $request, 'escola.encerrada', $tenant->codigo, ['motivo' => $encerrada->motivo_encerramento]);

        return $this->voltar($tenant)->with('success', 'Escola encerrada.');
    }

    /** Termina sessões e tokens de uma escola já suspensa: a Action recusa as outras e abre o contexto por dentro. */
    public function revogarAcessos(Request $request, Tenant $tenant, RevogarAcessosDeEscolaSuspensaAction $revogar, RegistarAuditoriaAction $auditoria): RedirectResponse
    {
        try {
            $revogados = $revogar->executar($tenant);
        } catch (OperacaoDeTenantRecusada $e) {
            return $this->voltar($tenant)->withErrors(['geral' => $e->getMessage()]);
        }

        $this->auditar($auditoria, $request, 'escola.acessos_revogados', $tenant->codigo, [
            'sessoes_revogadas' => $revogados['sessoes'],
            'tokens_revogados' => $revogados['tokens'],
        ]);

        return $this->voltar($tenant)->with('success', "{$revogados['sessoes']} sessão(ões) e {$revogados['tokens']} token(s) revogado(s).");
    }

    private function voltar(Tenant $tenant): RedirectResponse
    {
        return redirect()->route('plataforma.escolas.show', $tenant->codigo);
    }
}
