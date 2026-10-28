<?php

namespace Modules\Core\Traits;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use WeakMap;
use Modules\Core\Support\SanitizadorDeLogs;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Logs para qualquer classe (serviços, actions, controllers, jobs): `$this->logInfo('Pagamento registado', ['id' => $id])`.
 *
 * Cada entrada leva `[Classe::método:linha]` (quem chamou e onde) e, sozinhos, o `tenant_id`, o `user_id` e o `pedido`
 * (identificador que liga todas as linhas do mesmo pedido). Em segurança e estabilidade:
 *  - o contexto passa por `SanitizadorDeLogs`: segredos escondidos, tamanho e profundidade limitados, objetos resumidos;
 *  - a mensagem é uma só linha de tamanho limitado;
 *  - escrever um log NUNCA lança excepção nem derruba a operação de negócio;
 *  - em desempenho, o `debug` só é escrito em modo debug e a pilha de chamadas só se lê quando a entrada vai mesmo ser escrita.
 */
trait LogService
{
    protected function logDebug(string $message, array $context = []): void
    {
        // Em produção (APP_DEBUG=false) não custa nada: nem sequer se constrói a mensagem.
        if (config('app.debug')) {
            $this->escreverLog('debug', $message, $context);
        }
    }

    protected function logInfo(string $message, array $context = []): void
    {
        $this->escreverLog('info', $message, $context);
    }

    protected function logNotice(string $message, array $context = []): void
    {
        $this->escreverLog('notice', $message, $context);
    }

    protected function logWarning(string $message, array $context = []): void
    {
        $this->escreverLog('warning', $message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        $this->escreverLog('error', $message, $context);
    }

    protected function logCritical(string $message, array $context = []): void
    {
        $this->escreverLog('critical', $message, $context);
    }

    /**
     * Regista uma excepção (classe, mensagem e local — sem a pilha inteira) e devolve-a, para `throw $this->logException($e)`.
     * Excepções esperadas (validação, permissão, 4xx, regras de negócio) entram como `warning`, as inesperadas como `error`. Uma mesma excepção
     * que atravessa várias camadas (action dentro de action) só é registada uma vez.
     */
    protected function logException(Throwable $excecao, string $message = 'Excepção', array $context = []): Throwable
    {
        static $registadas = null;
        $registadas ??= new WeakMap;

        if (! isset($registadas[$excecao])) {
            $registadas[$excecao] = true;
            $esperada = $excecao instanceof ValidationException
                || $excecao instanceof AuthorizationException
                || ($excecao instanceof HttpExceptionInterface && $excecao->getStatusCode() < 500)
                // Excepções de regra de negócio (Modules\X\Exceptions\…, DomainException): o sistema recusou, não avariou.
                || $excecao instanceof \DomainException
                || (str_starts_with($excecao::class, 'Modules\\') && str_contains($excecao::class, '\\Exceptions\\'));

            $this->escreverLog($esperada ? 'warning' : 'error', $message, [...$context, 'excecao' => $excecao]);
        }

        return $excecao;
    }

    /**
     * Executa uma operação de negócio com registo: `info` quando conclui, `warning`/`error` (e a excepção volta a ser lançada,
     * intacta) quando falha. É o «try/catch» certo para actions e serviços: nunca engole o erro, nunca o regista duas vezes e
     * nunca depende de alguém se lembrar de o escrever.
     *
     * @template T
     *
     * @param  Closure(): T  $acao
     * @return T
     */
    protected function comLog(string $operacao, array $contexto, Closure $acao): mixed
    {
        try {
            $resultado = $acao();
        } catch (Throwable $excecao) {
            throw $this->logException($excecao, "{$operacao}: falhou", $contexto);
        }

        $this->logInfo("{$operacao}: concluída", $contexto);

        return $resultado;
    }

    /**
     * Contexto seguro a partir dos argumentos de um método: ids dos modelos, números e booleanos, e o tamanho das listas.
     * Texto, DTOs e pedidos ficam de fora (podem trazer dados pessoais) e parâmetros sensíveis nunca entram.
     *
     * @param  array<int, mixed>  $argumentos  `func_get_args()`
     * @return array<string, mixed>
     */
    protected function contextoDeArgumentos(array $argumentos, string $metodo = 'executar'): array
    {
        try {
            $parametros = (new ReflectionMethod($this, $metodo))->getParameters();
        } catch (Throwable) {
            return [];
        }

        $contexto = [];
        foreach ($argumentos as $i => $valor) {
            $parametro = $parametros[$i] ?? null;
            $nome = $parametro?->getName() ?? "arg{$i}";

            if ($parametro?->getAttributes(SensitiveParameter::class) || preg_match('/pass|senha|secret|token/i', $nome) === 1) {
                continue;
            }

            if ($valor instanceof Model) {
                $contexto[$nome.'_id'] = $valor->getKey();
            } elseif (is_int($valor) || is_float($valor) || is_bool($valor)) {
                $contexto[$nome] = $valor;
            } elseif (is_array($valor)) {
                $contexto[$nome.'_total'] = count($valor);
            }
        }

        return $contexto;
    }

    private function escreverLog(string $nivel, string $message, array $context): void
    {
        try {
            Log::{$nivel}(
                $this->construirMensagemDeLog($message),
                [...$this->contextoAutomaticoDeLog(), ...SanitizadorDeLogs::contexto($context)],
            );
        } catch (Throwable) {
            // Um log falhado (disco cheio, canal indisponível, contexto estranho) nunca pode estragar a operação.
        }
    }

    private function construirMensagemDeLog(string $message): string
    {
        // O «quem chamou» é o primeiro frame que não seja um auxiliar deste trait; a linha é a de onde esse auxiliar foi chamado.
        $auxiliares = ['construirMensagemDeLog', 'escreverLog', 'logDebug', 'logInfo', 'logNotice', 'logWarning', 'logError', 'logCritical', 'logException', 'comLog'];
        $pilha = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8);

        $indice = 1;
        while (isset($pilha[$indice]) && in_array($pilha[$indice]['function'], $auxiliares, true)) {
            $indice++;
        }

        $classe = $pilha[$indice]['class'] ?? static::class;
        $metodo = $pilha[$indice]['function'] ?? 'desconhecido';
        $linha = $pilha[$indice - 1]['line'] ?? '?';

        // Também a classe passa pelo filtro: o nome de uma classe anónima leva um byte NUL.
        return SanitizadorDeLogs::texto('['.$classe.'::'.$metodo.':'.$linha.'] '.$message, 1000);
    }

    /** @return array{tenant_id?: int, user_id?: int|string, pedido?: string} */
    private function contextoAutomaticoDeLog(): array
    {
        $extra = [];

        $tenant = app(TenantContext::class);
        if ($tenant->temTenant()) {
            $extra['tenant_id'] = $tenant->id();
        }

        if (($utilizador = auth()->id()) !== null) {
            $extra['user_id'] = $utilizador;
        }

        // Um identificador por pedido HTTP, criado à primeira linha de log: liga tudo o que aconteceu nesse pedido.
        if (! app()->runningInConsole() && app()->bound('request')) {
            $atributos = request()->attributes;
            $extra['pedido'] = $atributos->get('pedido_id') ?? tap(Str::random(8), fn (string $id) => $atributos->set('pedido_id', $id));
        }

        return $extra;
    }
}
