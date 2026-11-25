<?php

namespace Modules\Plataforma\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Plataforma\Actions\AbrirSessaoDoSuperAdminAction;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\Actions\VerificarDesafioDoisFactoresAction;
use Modules\Plataforma\Http\Controllers\Concerns\AuditaSemFalhar;
use Modules\Plataforma\Http\Requests\DesafioDoisFactoresRequest;
use Modules\Plataforma\Support\DesafioDoisFactores;
use Modules\Plataforma\Support\LimitadorDesafioPlataforma;

/** Segundo passo do login do painel, só para quem activou o 2FA. */
class DesafioDoisFactoresController extends Controller
{
    use AuditaSemFalhar;

    public function create(Request $request)
    {
        if (DesafioDoisFactores::adminPendente($request->session()) === null) {
            return redirect()->route('plataforma.login');
        }

        return Inertia::render('Plataforma/DesafioDoisFactores');
    }

    public function store(
        DesafioDoisFactoresRequest $request,
        VerificarDesafioDoisFactoresAction $verificar,
        AbrirSessaoDoSuperAdminAction $abrirSessao,
        LimitadorDesafioPlataforma $limitador,
        RegistarAuditoriaAction $auditoria,
    ) {
        $admin = DesafioDoisFactores::adminPendente($request->session());

        if ($admin === null) {
            return redirect()->route('plataforma.login');
        }

        $campo = $request->filled('recovery_code') ? 'recovery_code' : 'code';

        $segundos = $limitador->segundosDeBloqueio($admin, $request);

        if ($segundos !== null) {
            return back()->withErrors([$campo => "Demasiadas tentativas. Tente de novo dentro de {$segundos} segundos."]);
        }

        if (! $verificar->executar($admin, $request->input('code'), $request->input('recovery_code'))) {
            $limitador->registarFalha($admin, $request);
            $this->auditar($auditoria, $request, 'super_admin.2fa_desafio_falhado', null, [], $admin);

            return back()->withErrors([$campo => $campo === 'code' ? 'O código é inválido.' : 'O código de recuperação é inválido.']);
        }

        $limitador->limpar($admin, $request);
        DesafioDoisFactores::fechar($request->session());
        $abrirSessao->executar($request, $admin);

        return redirect()->route('plataforma.inicio');
    }
}
