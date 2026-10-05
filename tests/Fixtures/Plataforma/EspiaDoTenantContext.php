<?php

namespace Tests\Fixtures\Plataforma;

use Closure;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;

/**
 * Espião do TenantContext para os testes da Plataforma (regra de ouro, em runtime).
 *
 * Substitui o contexto do container e FALHA se `definir`, `limpar`, `executarComo` ou `lembrar` forem
 * chamados a partir de código da Plataforma. O que conta é o sítio da chamada (o primeiro frame fora
 * do próprio TenantContext e deste espião), e não toda a pilha: um controller da Plataforma que chama
 * uma Action do módulo Tenant, e esta abre o contexto, é o desenho previsto e passa.
 *
 * Código de teste da Plataforma (`Modules/Plataforma/tests/`) não conta: os auxiliares de pedido
 * limpam o contexto entre pedidos, como faria um processo novo.
 */
class EspiaDoTenantContext extends TenantContext
{
    /** Fragmentos de caminho cujo código não pode operar o contexto. */
    public const PROIBIDO = '/Modules/Plataforma/';

    private const ISENTO = '/Modules/Plataforma/tests/';

    /** Chamadas aceites (de fora da Plataforma): prova que o espião está mesmo no caminho. */
    public int $chamadasLegitimas = 0;

    /** @var array<string, true> ficheiros de onde vieram as chamadas aceites (caminho => true) */
    private array $ficheirosLegitimos = [];

    /** @var string[] violações, acumuladas mesmo que a excepção seja engolida por um try/catch */
    private array $violacoes = [];

    /** @param  string[]  $proibidosExtra  fragmentos de caminho adicionais (para provar o próprio espião) */
    public function __construct(private array $proibidosExtra = []) {}

    public function definir(TenantAtual $tenant): void
    {
        $this->vigiar('definir');
        parent::definir($tenant);
    }

    public function limpar(): void
    {
        $this->vigiar('limpar');
        parent::limpar();
    }

    public function executarComo(TenantAtual $tenant, Closure $fn): mixed
    {
        $this->vigiar('executarComo');

        return parent::executarComo($tenant, $fn);
    }

    public function lembrar(string $chave, Closure $fn): mixed
    {
        $this->vigiar('lembrar');

        return parent::lembrar($chave, $fn);
    }

    /** Passa o estado de um contexto anterior (o do setUp) para este, sem contar como chamada. */
    public function herdarDe(TenantContext $anterior): void
    {
        if ($anterior->temTenant()) {
            parent::definir($anterior->atual());
        }
    }

    /** Um ficheiro (caminho absoluto) pode operar o contexto? Falso para código da Plataforma (excepto os seus testes). */
    public static function caminhoProibido(string $ficheiro, array $proibidosExtra = []): bool
    {
        $caminho = str_replace('\\', '/', $ficheiro);

        return (str_contains($caminho, self::PROIBIDO) && ! str_contains($caminho, self::ISENTO))
            || array_filter($proibidosExtra, fn (string $f) => str_contains($caminho, $f)) !== [];
    }

    private function vigiar(string $operacao): void
    {
        $ficheiro = $this->ficheiroDoChamador();

        if (self::caminhoProibido($ficheiro, $this->proibidosExtra)) {
            $mensagem = "Regra de ouro violada: {$operacao}() chamado a partir de código da Plataforma ({$ficheiro}).";
            // Fica registada ANTES de lançar: um `catch (Throwable)` no código da Plataforma não a apaga.
            $this->violacoes[] = $mensagem;

            throw new AssertionFailedError($mensagem);
        }

        $this->chamadasLegitimas++;
        $this->ficheirosLegitimos[str_replace('\\', '/', $ficheiro)] = true;
    }

    /** @return string[] caminhos de onde vieram as chamadas aceites */
    public function ficheirosLegitimos(): array
    {
        return array_keys($this->ficheirosLegitimos);
    }

    /** Falha se alguma violação foi registada, tenha ou não a excepção chegado ao fim. */
    public function assertNenhumaViolacao(): void
    {
        if ($this->violacoes !== []) {
            $mensagem = implode("\n", array_unique($this->violacoes));
            $this->violacoes = [];

            throw new AssertionFailedError("O espião do contexto registou violações (podem ter sido engolidas por um catch):\n{$mensagem}");
        }
    }

    /** Para os testes que provocam a violação de propósito e já a verificaram. */
    public function esquecerViolacoes(): void
    {
        $this->violacoes = [];
    }

    private function ficheiroDoChamador(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $ficheiro = $frame['file'] ?? null;

            // O vendor (tap(), proxies, higher-order) não é o sítio da chamada: segue-se para quem o usou.
            if ($ficheiro !== null && ! str_contains(str_replace('\\', '/', $ficheiro), '/vendor/') && $ficheiro !== __FILE__ && $ficheiro !== (new ReflectionClass(TenantContext::class))->getFileName()) {
                return $ficheiro;
            }
        }

        return '';
    }
}
