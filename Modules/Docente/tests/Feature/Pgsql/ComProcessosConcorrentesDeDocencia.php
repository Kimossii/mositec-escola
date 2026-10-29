<?php

namespace Modules\Docente\Tests\Feature\Pgsql;

use RuntimeException;

/**
 * Sessões PostgreSQL REAIS e simultâneas para os testes de concorrência das atribuições docentes: cada operação
 * corre num processo PHP filho (arranque completo da aplicação, tenant do teste), com a sua própria ligação. O pai
 * e os filhos só falam por stdout (uma linha JSON) e pela base. Requer PgsqlConcorrenciaTestCase. Mesmo mecanismo
 * de Modules/Financeiro/tests/Feature/Pgsql/ComProcessosConcorrentes.php.
 *
 * Operações: atribuir (AtribuirDocenteAction).
 */
trait ComProcessosConcorrentesDeDocencia
{
    /** @var list<string> */
    private array $scriptsTemporarios = [];

    private function scriptDoFilho(): string
    {
        $codigo = <<<'PHP'
<?php
$args = json_decode(base64_decode($argv[1]), true);
require $args['base'] . '/vendor/autoload.php';
$app = require $args['base'] . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(Modules\Core\Tenancy\TenantContext::class)->definir(Modules\Tenant\Models\Tenant::findOrFail($args['tenant'])->paraTenantAtual());
$saida = fn (array $dados) => print(PHP_EOL . '@@' . json_encode($dados, JSON_UNESCAPED_UNICODE) . PHP_EOL);
try {
    // Aquece a ligação e espera pela hora de partida (as operações arrancam juntas).
    DB::select('select 1');
    while (microtime(true) < $args['partida']) {
        usleep(200);
    }
    $a = $args['dados'];
    if ($args['op'] === 'atribuir') {
        $r = app(Modules\Docente\Actions\AtribuirDocenteAction::class)->executar(new Modules\Docente\DTO\AtribuicaoDocenteDTO(
            docente_id: $a['docente'],
            turma_id: $a['turma'],
            disciplina_id: $a['disciplina'],
            papel: Modules\Docente\Enums\PapelDocente::from($a['papel']),
            data_inicio: $a['inicio'],
            data_fim: $a['fim'] ?? null,
        ));
        $saida(['ok' => true, 'atribuicao' => $r->id]);
    } else {
        throw new RuntimeException('operação desconhecida');
    }
} catch (Illuminate\Validation\ValidationException $e) {
    $saida(['ok' => false, 'tipo' => 'validacao', 'campos' => array_keys($e->errors()), 'mensagem' => collect($e->errors())->flatten()->first()]);
} catch (Throwable $e) {
    $saida(['ok' => false, 'tipo' => get_class($e), 'sqlstate' => $e instanceof Illuminate\Database\QueryException ? ($e->errorInfo[0] ?? null) : null, 'mensagem' => substr($e->getMessage(), 0, 400)]);
}
PHP;
        $ficheiro = tempnam(sys_get_temp_dir(), 'docente-filho-') . '.php';
        file_put_contents($ficheiro, $codigo);
        $this->scriptsTemporarios[] = $ficheiro;

        return $ficheiro;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{proc: resource, pipes: array<int, resource>, saida: string, erro: string}
     */
    private function lancar(string $operacao, array $dados, float $partida = 0.0): array
    {
        $args = base64_encode(json_encode([
            'base' => base_path(), 'op' => $operacao, 'dados' => $dados, 'tenant' => $this->tenant->id, 'partida' => $partida,
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

    protected function tearDown(): void
    {
        foreach ($this->scriptsTemporarios as $ficheiro) {
            @unlink($ficheiro);
            @unlink(substr($ficheiro, 0, -4));
        }

        parent::tearDown();
    }
}
