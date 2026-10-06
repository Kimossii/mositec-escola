<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RotaRegistada;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Usuario e Permissão têm rotas de escrita de propósito sem `can:` no
 * grupo — a ability certa depende do pedido (ex.: criar um Admin Escola
 * exige autorizacao.criar, não usuario.criar) e só o FormRequest ou o
 * controller sabem decidir isso. RotasPermissaoReconhecidaTest já garante
 * que todo `can:` presente é uma ability reconhecida; este teste cobre o
 * caso oposto — nenhuma rota de escrita autenticada pode ficar sem
 * nenhuma das duas formas de autorização, por regressão (ex.: um novo
 * FormRequest com `authorize(): bool { return true; }` por esquecimento).
 */
class RotasEscritaAutorizadasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rotas de auto-serviço do próprio utilizador autenticado (a sessão
     * dele, o perfil dele) — não há ability de negócio para "editar a
     * minha própria password". As de user/* são do pacote Fortify, fora
     * do código do projecto.
     */
    private const ISENTAS = [
        'logout',
        'alterar-senha',
        'api/v1/autenticacaoApi/api/logout',
        'api/v1/autenticacaoApi/api/logout-all-devices',
        'user/profile-information',
        'user/confirm-password',
        'user/two-factor-authentication',
        'user/confirmed-two-factor-authentication',
        'user/two-factor-recovery-codes',
    ];

    public function test_toda_rota_de_escrita_autenticada_tem_can_ou_autorizacao_verificavel(): void
    {
        $semAutorizacao = [];

        foreach (Route::getRoutes() as $rota) {
            if (!$this->eEscritaAutenticada($rota) || $this->eIsenta($rota)) {
                continue;
            }

            if ($this->temMiddlewareCan($rota) || $this->temAutorizacaoVerificavel($rota)) {
                continue;
            }

            $metodos = implode('|', array_diff($rota->methods(), ['HEAD']));
            $semAutorizacao[] = "{$metodos} {$rota->uri()} => {$rota->getActionName()}";
        }

        $this->assertEmpty(
            $semAutorizacao,
            "Rotas de escrita autenticadas sem 'can:' nem autorização verificável no FormRequest/controller:\n"
                .implode("\n", $semAutorizacao)
        );
    }

    private function eEscritaAutenticada(RotaRegistada $rota): bool
    {
        $metodos = array_diff($rota->methods(), ['HEAD']);
        if (!array_intersect($metodos, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return false;
        }

        $middleware = $rota->gatherMiddleware();

        return in_array('auth', $middleware, true)
            || in_array('auth:web', $middleware, true)
            || in_array('auth:sanctum', $middleware, true);
    }

    private function eIsenta(RotaRegistada $rota): bool
    {
        if (in_array($rota->uri(), self::ISENTAS, true)) {
            return true;
        }

        // Pacote de terceiros (Fortify): gestão da própria conta, já
        // coberta pela lista acima por URI — isto apanha o resto.
        return str_starts_with($rota->getActionName(), 'Laravel\\Fortify\\');
    }

    private function temMiddlewareCan(RotaRegistada $rota): bool
    {
        foreach ($rota->gatherMiddleware() as $middleware) {
            if (str_starts_with($middleware, 'can:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Autorização "verificável" = ou o controller chama `$this->authorize`/
     * `Gate::` no corpo do método da rota, ou um dos parâmetros é um
     * FormRequest cujo `authorize()` faz o mesmo. Um `authorize()` que só
     * devolve `true` (ou não faz nada) NÃO conta — é exactamente a porta
     * aberta que este teste existe para apanhar.
     */
    private function temAutorizacaoVerificavel(RotaRegistada $rota): bool
    {
        $accao = $rota->getActionName();
        if (!str_contains($accao, '@')) {
            return false; // rota fechada em closure: sem FormRequest nem controller a inspeccionar
        }

        [$classe, $metodo] = explode('@', $accao);
        if (!class_exists($classe) || !method_exists($classe, $metodo)) {
            return false;
        }

        $reflexao = new ReflectionMethod($classe, $metodo);

        foreach ($reflexao->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            if (
                $tipo instanceof \ReflectionNamedType
                && !$tipo->isBuiltin()
                && is_subclass_of($tipo->getName(), FormRequest::class)
                && method_exists($tipo->getName(), 'authorize')
                && $this->corpoTemVerificacaoReal(new ReflectionMethod($tipo->getName(), 'authorize'))
            ) {
                return true;
            }
        }

        return $this->corpoTemVerificacaoReal($reflexao);
    }

    private function corpoTemVerificacaoReal(ReflectionMethod $metodo): bool
    {
        $ficheiro = $metodo->getFileName();
        $linhaInicial = $metodo->getStartLine();
        $linhaFinal = $metodo->getEndLine();

        if ($ficheiro === false || $linhaInicial === false || $linhaFinal === false) {
            return false;
        }

        $corpo = implode('', array_slice(file($ficheiro), $linhaInicial - 1, $linhaFinal - $linhaInicial + 1));

        return (bool) preg_match('/->can\(|Gate::|->authorize\(|Response::deny\(|Response::allow\(/', $corpo);
    }
}
