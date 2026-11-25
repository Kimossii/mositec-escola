<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Modules\Plataforma\Actions\ConfirmarDoisFactoresSuperAdminAction;
use Modules\Plataforma\Actions\DesactivarDoisFactoresSuperAdminAction;
use Modules\Plataforma\Actions\IniciarDoisFactoresSuperAdminAction;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Http\Controllers\Concerns\AuditaSemFalhar;
use Modules\Plataforma\Http\Requests\CodigoDoisFactoresRequest;
use Modules\Plataforma\Http\Requests\ConfirmarSenhaRequest;

/** Segurança da própria conta do super admin: activar e desactivar o 2FA (sempre opcional). */
class SegurancaController extends Controller
{
    use AuditaSemFalhar;

    public function index(Request $request)
    {
        return Inertia::render('Plataforma/Seguranca', [
            'doisFactores' => ['activo' => $request->user('plataforma')->hasEnabledTwoFactorAuthentication()],
        ]);
    }

    public function iniciar(ConfirmarSenhaRequest $request, IniciarDoisFactoresSuperAdminAction $acao): JsonResponse
    {
        try {
            return response()->json($acao->executar($request->user('plataforma')));
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function confirmar(CodigoDoisFactoresRequest $request, ConfirmarDoisFactoresSuperAdminAction $acao, RegistarAuditoriaAction $auditoria): JsonResponse
    {
        $codigos = $acao->executar($request->user('plataforma'), $request->validated('code'));

        $this->auditar($auditoria, $request, 'super_admin.2fa_activado', null);

        return response()->json(['codigos' => $codigos]);
    }

    public function desactivar(ConfirmarSenhaRequest $request, DesactivarDoisFactoresSuperAdminAction $acao, RegistarAuditoriaAction $auditoria): Response
    {
        $acao->executar($request->user('plataforma'));

        $this->auditar($auditoria, $request, 'super_admin.2fa_desactivado', null);

        return response()->noContent();
    }
}
