<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;
use Modules\Core\Tenancy\Contracts\RecuperaAdministradorDoTenant;
use Modules\Core\Tenancy\Enums\MotivoRecusaRecuperacao;
use Modules\Core\Tenancy\Exceptions\RecuperacaoDeAdministradorRecusada;
use Modules\Core\Tenancy\Provisioning\AdministradorDaEscola;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Http\Controllers\Concerns\AuditaSemFalhar;
use Modules\Plataforma\Http\Requests\RecuperarAdministradorRequest;
use Modules\Tenant\Models\Tenant;

/**
 * Recuperar o administrador de uma escola. Só conhece o contrato do Core: o contexto da escola abre-se
 * (e fecha-se) lá dentro, nunca aqui. Sem o módulo que o implementa, resolver o contrato falha alto.
 * O painel só obtém nome, e-mail e estado dos administradores.
 */
class AdministradorEscolaController extends Controller
{
    use AuditaSemFalhar;

    /** Mesma mensagem para e-mail inexistente e e-mail de outra escola: não revela onde existe. */
    private const MENSAGEM_EMAIL_NAO_ENCONTRADO = 'Não foi encontrado nenhum administrador activo com esse e-mail nesta escola.';

    public function index(Tenant $tenant, RecuperaAdministradorDoTenant $recuperacao): JsonResponse
    {
        try {
            $administradores = $recuperacao->administradores($tenant->paraTenantAtual());
        } catch (RecuperacaoDeAdministradorRecusada $e) {
            return response()->json(['message' => $this->mensagemDe($e)], 422);
        }

        return response()->json([
            'administradores' => array_map(fn (AdministradorDaEscola $a) => [
                'nome' => $a->nome,
                'email' => $a->email,
            ], $administradores),
        ]);
    }

    public function recuperar(
        RecuperarAdministradorRequest $request,
        Tenant $tenant,
        RecuperaAdministradorDoTenant $recuperacao,
        RegistarAuditoriaAction $auditoria,
    ): RedirectResponse {
        $email = $request->validated('email');
        $email = is_string($email) && trim($email) !== '' ? trim($email) : null;

        try {
            $credencial = $recuperacao->recuperar($tenant->paraTenantAtual(), $email);
        } catch (RecuperacaoDeAdministradorRecusada $e) {
            // Erro do campo `email` só quando há e-mail indicado a que o associar; sem ele, é geral.
            $campo = match ($e->motivo) {
                MotivoRecusaRecuperacao::ESCOLA_NAO_ACTIVA, MotivoRecusaRecuperacao::SEM_ADMINISTRADORES => 'geral',
                MotivoRecusaRecuperacao::DESACTIVADO => $email === null ? 'geral' : 'email',
                default => 'email',
            };

            return redirect()->route('plataforma.escolas.show', $tenant->codigo)->withErrors([$campo => $this->mensagemDe($e)]);
        }

        // Só o e-mail do administrador: a senha nunca vai para a auditoria.
        $this->auditar($auditoria, $request, 'administrador.recuperado', $tenant->codigo, ['email' => $credencial->email]);

        return redirect()->route('plataforma.escolas.show', $tenant->codigo)
            ->with('success', 'Acesso do administrador recuperado.')
            // Mesmo formato do Plano 3b e da criação de escola. Cifrada: o payload da sessão nunca a guarda em claro.
            ->with('senha_temporaria', [
                'codigo' => $tenant->codigo,
                'email' => $credencial->email,
                'senha' => Crypt::encryptString($credencial->senha()),
            ]);
    }

    private function mensagemDe(RecuperacaoDeAdministradorRecusada $e): string
    {
        return match ($e->motivo) {
            MotivoRecusaRecuperacao::ESCOLA_NAO_ACTIVA => 'Só é possível recuperar o administrador de uma escola activa.',
            MotivoRecusaRecuperacao::SEM_ADMINISTRADORES => 'Esta escola não tem administradores activos.',
            MotivoRecusaRecuperacao::NAO_ENCONTRADO => self::MENSAGEM_EMAIL_NAO_ENCONTRADO,
            MotivoRecusaRecuperacao::DESACTIVADO => 'Esse administrador está desactivado: a conta tem de ser reactivada na escola antes de recuperar o acesso.',
            MotivoRecusaRecuperacao::VARIOS_ADMINISTRADORES => 'Esta escola tem vários administradores activos: escolha qual deles recuperar.',
        };
    }
}
