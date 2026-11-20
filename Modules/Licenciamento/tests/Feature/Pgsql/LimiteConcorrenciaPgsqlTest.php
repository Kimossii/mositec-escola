<?php

namespace Modules\Licenciamento\Tests\Feature\Pgsql;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Licenciamento\Contracts\GarantiaDeLimites;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * Duas sessões reais a disputar o último lugar: o `garantir` bloqueia (FOR UPDATE) a linha de licenciamento_direitos do
 * tenant dentro da transacção, por isso uma segunda sessão não consegue contar/criar enquanto a primeira não terminar
 * (desiste por lock_timeout, SQLSTATE 55P03). Só corre com a base mositec_escola_test (grupo pgsql).
 */
#[Group('pgsql')]
class LimiteConcorrenciaPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComLicencaDeTeste;

    public function test_contas_concorrentes_nao_ultrapassam_o_limite(): void
    {
        $this->prepararLicencas();
        $this->congelarRelogioDeTeste();
        app(InstalarLicencaAction::class)->executar($this->tenant->id, $this->tenant->codigo, $this->tokenDeTeste(['lim' => ['alunos' => 1, 'utilizadores' => null, 'estabelecimentos' => 1]]));

        DB::beginTransaction();
        try {
            // Sessão 1: ocupa o último lugar e segura o bloqueio até ao fim da transacção.
            app(GarantiaDeLimites::class)->garantir(Recurso::ALUNOS);

            // Sessão 2: não consegue passar pelo mesmo bloqueio (se conseguisse, contaria 0 e também criaria: ultrapassava-se).
            try {
                $this->outraLigacao()->select('select id from licenciamento_direitos where tenant_id = ? for update', [$this->tenant->id]);
                $this->fail('A segunda sessão devia ter ficado bloqueada à espera do lugar.');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->getCode());
            }
        } finally {
            DB::rollBack();
        }
    }
}
