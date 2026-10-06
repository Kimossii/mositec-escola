<?php

namespace Tests\Fixtures\Tenancy;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Core\Tenancy\Jobs\ComTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use RuntimeException;

/** Job de exemplo (só para testes): regista o que vê dentro do contexto. */
class JobDeTeste implements ShouldQueue
{
    use ComTenant, Queueable;

    /** @var list<array{tenant: string, estabelecimento: string, memoria: ?string}> */
    public static array $execucoes = [];

    public function __construct(public bool $falhar = false, public ?Estabelecimento $estabelecimento = null) {}

    public function handle(): void
    {
        self::$execucoes[] = [
            'tenant' => app(TenantContext::class)->atual()->codigo,
            'estabelecimento' => Estabelecimento::current()->nome,
            'modelo' => $this->estabelecimento?->nome,
        ];

        if ($this->falhar) {
            throw new RuntimeException('falha de teste');
        }
    }
}
