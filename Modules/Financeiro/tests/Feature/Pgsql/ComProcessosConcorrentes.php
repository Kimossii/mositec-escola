<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use RuntimeException;

/**
 * Sessões PostgreSQL REAIS e simultâneas para os testes de concorrência: cada operação corre num processo PHP
 * filho (arranque completo da aplicação, tenant do teste, relógio congelado), com a sua própria ligação.
 * O pai e os filhos só falam por stdout (uma linha JSON) e pela base. Requer PgsqlConcorrenciaTestCase.
 *
 * Operações: alterar (AlterarPrecoPlanoAction), gerar (GerarPropinasService por turma), cancelar (matrícula
 * Cancelada → cancela as propinas futuras).
 */
trait ComProcessosConcorrentes
{
    /** @var list<string> */
    private array $scriptsTemporarios = [];

    private function scriptDoFilho(): string
    {
        $base = base_path();
        $codigo = <<<'PHP'
<?php
$args = json_decode(base64_decode($argv[1]), true);
require $args['base'] . '/vendor/autoload.php';
$app = require $args['base'] . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Carbon::setTestNow(Carbon\CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
app(Modules\Core\Tenancy\TenantContext::class)->definir(Modules\Tenant\Models\Tenant::findOrFail($args['tenant'])->paraTenantAtual());
$saida = fn (array $dados) => print(PHP_EOL . '@@' . json_encode($dados, JSON_UNESCAPED_UNICODE) . PHP_EOL);
try {
    // Aquece a ligação e espera pela hora de partida (as duas operações arrancam juntas).
    DB::select('select 1');
    while (microtime(true) < $args['partida']) {
        usleep(200);
    }
    usleep((int) ($args['atraso'] ?? 0) * 1000);
    $a = $args['dados'];
    if ($args['op'] === 'alterar') {
        $dto = new Modules\Financeiro\DTO\AlterarPrecoPlanoDTO(
            valorNovo: Modules\Financeiro\Support\Dinheiro::deUnidadesMenores($a['valor']),
            mesEfeito: Carbon\CarbonImmutable::createFromFormat('!Y-m', $a['mes']),
            modo: Modules\Financeiro\Enums\ModoAlteracaoPreco::from($a['modo']),
            motivo: $a['motivo'],
            impressaoDigital: $a['impressao'],
            confirmaValoresDiferentes: true,
        );
        $plano = Modules\Financeiro\Models\PlanoPropina::findOrFail($a['plano']);
        $r = app(Modules\Financeiro\Actions\AlterarPrecoPlanoAction::class)->executar($plano, $dto);
        $saida(['ok' => true, 'alteracao' => $r->id, 'plano_novo' => $r->plano_novo_id]);
    } elseif ($args['op'] === 'gerar') {
        $r = app(Modules\Financeiro\Services\GerarPropinasService::class)->gerar(new Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $a['ano'],
            ambito: Modules\Financeiro\Enums\AmbitoGeracaoPropinas::TURMA,
            origem: Modules\Financeiro\Enums\OrigemGeracaoPropina::MANUAL,
            alvo_id: $a['turma'],
        ));
        $saida(['ok' => true, 'totais' => $r->totais()]);
    } elseif ($args['op'] === 'cancelar') {
        $m = Modules\Matricula\Models\Matricula::findOrFail($a['matricula']);
        app(Modules\Matricula\Actions\AlterarEstadoMatriculaAction::class)->executar($m, Modules\Matricula\Enums\EstadoMatriculaEnum::CANCELADA, null, '2026-10-01');
        $saida(['ok' => true]);
    } else {
        throw new RuntimeException('operação desconhecida');
    }
} catch (Illuminate\Validation\ValidationException $e) {
    $saida(['ok' => false, 'tipo' => 'validacao', 'campos' => array_keys($e->errors()), 'mensagem' => collect($e->errors())->flatten()->first()]);
} catch (Throwable $e) {
    $saida(['ok' => false, 'tipo' => get_class($e), 'sqlstate' => $e instanceof Illuminate\Database\QueryException ? ($e->errorInfo[0] ?? null) : null, 'mensagem' => substr($e->getMessage(), 0, 400)]);
}
PHP;
        $ficheiro = tempnam(sys_get_temp_dir(), 'f4-filho-') . '.php';
        file_put_contents($ficheiro, $codigo);
        $this->scriptsTemporarios[] = $ficheiro;

        return $ficheiro;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{proc: resource, pipes: array<int, resource>, saida: string, erro: string}
     */
    private function lancar(string $operacao, array $dados, float $partida = 0.0, int $atrasoMs = 0): array
    {
        $args = base64_encode(json_encode([
            'base' => base_path(), 'op' => $operacao, 'dados' => $dados, 'tenant' => $this->tenant->id, 'partida' => $partida, 'atraso' => $atrasoMs,
        ]));
        $ambiente = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'mositec_escola_test',
        ]);
        $processo = proc_open([PHP_BINARY, $this->scriptDoFilho(), $args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $ambiente);

        if (! is_resource($processo)) {
            throw new RuntimeException('Não foi possível lançar o processo filho.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['proc' => $processo, 'pipes' => $pipes, 'saida' => '', 'erro' => ''];
    }

    /** @param array{proc: resource, pipes: array<int, resource>, saida: string, erro: string} $filho */
    private function aCorrer(array &$filho): bool
    {
        $filho['saida'] .= (string) stream_get_contents($filho['pipes'][1]);
        $filho['erro'] .= (string) stream_get_contents($filho['pipes'][2]);

        return proc_get_status($filho['proc'])['running'];
    }

    /**
     * Espera pelo fim do filho e devolve o JSON que ele escreveu.
     *
     * @param  array{proc: resource, pipes: array<int, resource>, saida: string, erro: string}  $filho
     * @return array<string, mixed>
     */
    private function esperar(array &$filho, int $segundos = 90): array
    {
        $limite = microtime(true) + $segundos;

        while ($this->aCorrer($filho)) {
            if (microtime(true) > $limite) {
                proc_terminate($filho['proc'], 9);
                throw new RuntimeException('O processo filho não terminou (possível bloqueio permanente).');
            }

            usleep(20_000);
        }

        $this->aCorrer($filho);
        proc_close($filho['proc']);

        if (! preg_match('/@@(\{.*\})\s*$/s', $filho['saida'], $m)) {
            throw new RuntimeException("Filho sem resposta. stdout: {$filho['saida']} stderr: {$filho['erro']}");
        }

        return json_decode($m[1], true);
    }

    /** Há pelo menos uma sessão à espera de um bloqueio (pg_locks.granted = false). */
    private function haSessoesABloquear(): bool
    {
        return (int) $this->outraLigacao()->selectOne('select count(*) as n from pg_locks where not granted')->n > 0;
    }

    /** Deixa o filho arrancar (boot da aplicação) e dá-lhe tempo para ficar preso num bloqueio. */
    private function esperarQueOFilhoFiqueABloquear(array &$filho, int $maximoMs = 15000): bool
    {
        $limite = microtime(true) + $maximoMs / 1000;

        while (microtime(true) < $limite) {
            if (! $this->aCorrer($filho)) {
                return false;
            }

            if ($this->haSessoesABloquear()) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    private function deadlocksDaBase(): int
    {
        return (int) $this->outraLigacao()->selectOne('select deadlocks as n from pg_stat_database where datname = current_database()')->n;
    }

    protected function tearDown(): void
    {
        foreach ($this->scriptsTemporarios as $ficheiro) {
            @unlink($ficheiro);
            @unlink(substr($ficheiro, 0, -4));
        }

        parent::tearDown();
    }
}
