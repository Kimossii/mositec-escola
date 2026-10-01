<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Console\Command;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Estabelecimento\Models\Estabelecimento;
use RuntimeException;

/** Comando de exemplo (só para testes) com a convenção --tenant / --todos. */
class ComandoDeTeste extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'teste:tenancy {--rebentar-em= : código do tenant onde falhar} {--despachar : devolve um PendingDispatch}';

    protected $description = 'Só para testes';

    /** @var list<string> */
    public static array $corridos = [];

    public function handle(): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) {
            if ($this->option('despachar')) {
                return JobDeTeste::dispatch();
            }

            if ($this->option('rebentar-em') === $tenant->codigo) {
                throw new RuntimeException('segredo-que-nao-deve-aparecer');
            }

            self::$corridos[] = $tenant->codigo . '=' . Estabelecimento::current()->nome;
        });
    }
}
