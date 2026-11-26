<?php

namespace Modules\Autenticacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Autenticacao\Actions\ConcluirRecuperacaoDeSenhaAction;
use Modules\Autenticacao\Actions\PedirRecuperacaoDeSenhaAction;
use Modules\Autenticacao\Http\Requests\RecuperarSenhaRequest;
use Modules\Autenticacao\Support\ElegibilidadeParaRecuperarSenha;
use Modules\Core\Comunicacao\Email\EmailDisponivel;
use Modules\Core\Tenancy\TenantContext;

/**
 * Recuperação de senha por email. Páginas públicas (sem sessão) no layout de visitante. Sem escola resolvida (host central)
 * não há recuperação: responde 404, como uma rota que não existe.
 */
class RecuperacaoDeSenhaController extends Controller
{
    public function __construct(private TenantContext $contexto)
    {
        abort_unless($contexto->temTenant(), 404);
    }

    /** Só existe quando há email configurado E algum perfil elegível; senão a página nem aparece. */
    private function disponivel(): bool
    {
        return app(EmailDisponivel::class)->disponivel() && app(ElegibilidadeParaRecuperarSenha::class)->haPerfisElegiveis();
    }

    public function pedirForm(): Response
    {
        abort_unless($this->disponivel(), 404);

        return Inertia::render('Autenticacao/EsqueciSenha', ['enviado' => false])->rootView('layouts.guest');
    }

    /** Resposta IGUAL para qualquer email: quem pede nunca descobre se a conta existe nem se é elegível. */
    public function pedir(Request $request, PedirRecuperacaoDeSenhaAction $acao): RedirectResponse
    {
        abort_unless($this->disponivel(), 404);

        $inicio = hrtime(true);
        $acao->executar((string) $request->input('email'));
        $this->igualarTempoDeResposta($inicio);

        return redirect()->route('senha.esqueci.enviado');
    }

    /** Espera o que faltar para o tempo mínimo configurado (com variação), para todas as respostas demorarem o mesmo. */
    private function igualarTempoDeResposta(int $inicio): void
    {
        $minimo = (int) config('comunicacao.recuperacao_senha.tempo_minimo_ms');

        if ($minimo <= 0) {
            return;
        }

        $alvoMs = $minimo + random_int(0, (int) ($minimo / 6));
        $decorridoMs = (hrtime(true) - $inicio) / 1_000_000;

        if ($decorridoMs < $alvoMs) {
            usleep((int) (($alvoMs - $decorridoMs) * 1000));
        }
    }

    public function pedidoEnviado(): Response
    {
        abort_unless($this->disponivel(), 404);

        return Inertia::render('Autenticacao/EsqueciSenha', ['enviado' => true])->rootView('layouts.guest');
    }

    /** Destino da ligação do email (assinada). Mostra o formulário, ou «ligação inválida» se já não der direito a nada. */
    public function form(int $id, string $hash, ConcluirRecuperacaoDeSenhaAction $acao): Response
    {
        $valida = $acao->contaValida($id, $hash) !== null;

        return Inertia::render('Autenticacao/RecuperarSenha', ['estado' => $valida ? 'formulario' : 'invalido'])->rootView('layouts.guest');
    }

    public function redefinir(int $id, string $hash, RecuperarSenhaRequest $request, ConcluirRecuperacaoDeSenhaAction $acao): RedirectResponse
    {
        if (! $acao->executar($id, $hash, $request->validated('password'))) {
            return redirect()->back();
        }

        return redirect()->route('senha.recuperada');
    }

    public function recuperada(): Response
    {
        return Inertia::render('Autenticacao/RecuperarSenha', ['estado' => 'concluido'])->rootView('layouts.guest');
    }
}
