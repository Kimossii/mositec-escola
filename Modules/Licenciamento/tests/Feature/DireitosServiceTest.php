<?php

namespace Modules\Licenciamento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\TenantContext;
use Modules\Licenciamento\Assinatura\CodecoDeLicenca;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Licenciamento\Enums\ModoDeOperacao;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Services\DireitosService;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Tests\TestCase;

class DireitosServiceTest extends TestCase
{
    use ComLicencaDeTeste;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararLicencas();
        $this->congelarRelogioDeTeste();
    }

    private function servico(): DireitosService
    {
        return new DireitosService(app(TenantContext::class), app(CodecoDeLicenca::class));
    }

    private function instalar(string $token): void
    {
        app(InstalarLicencaAction::class)->executar($this->tenant->id, $this->tenant->codigo, $token);
    }

    public function test_direitos_service_devolve_null_sem_licenca(): void
    {
        $this->assertNull($this->servico()->doTenantActual());
        $this->assertSame(ModoDeOperacao::BLOQUEADO, $this->servico()->modo());
    }

    public function test_modo_normal_com_flag_inactiva_mesmo_sem_licenca(): void
    {
        config(['licenciamento.activo' => false]);

        $this->assertSame(ModoDeOperacao::NORMAL, $this->servico()->modo());
    }

    public function test_devolve_direitos_e_modo_normal_com_licenca_valida(): void
    {
        $this->instalar($this->tokenDeTeste());

        $this->assertSame('lic-1', $this->servico()->doTenantActual()?->id);
        $this->assertSame(ModoDeOperacao::NORMAL, $this->servico()->modo());
    }

    public function test_modo_aviso_perto_do_fim(): void
    {
        $this->instalar($this->tokenDeTeste());
        $this->congelarRelogioDeTeste('2026-12-20T00:00:00+00:00');

        $this->assertSame(ModoDeOperacao::AVISO, $this->servico()->modo());
    }

    public function test_revogada_bloqueia(): void
    {
        $this->instalar($this->tokenDeTeste());
        DireitosLocais::query()->update(['revogada_em' => now()]);

        $this->assertTrue($this->servico()->revogadaDoTenantActual());
        $this->assertSame(ModoDeOperacao::BLOQUEADO, $this->servico()->modo());
    }

    public function test_token_adulterado_na_bd_nao_da_direitos(): void
    {
        $this->instalar($this->tokenDeTeste());
        $token = DireitosLocais::query()->sole()->token;
        // Troca um carácter do payload: a assinatura deixa de bater.
        $partes = explode('.', $token);
        $partes[2][5] = $partes[2][5] === 'A' ? 'B' : 'A';
        DB::table('licenciamento_direitos')->update(['token' => implode('.', $partes)]);

        $this->assertNull($this->servico()->doTenantActual());
        $this->assertSame(ModoDeOperacao::BLOQUEADO, $this->servico()->modo());
    }

    public function test_editar_a_coluna_claims_nao_concede_direitos(): void
    {
        $this->instalar($this->tokenDeTeste(['lim' => ['alunos' => 5, 'utilizadores' => null]]));
        DB::table('licenciamento_direitos')->update(['claims' => json_encode(['lim' => ['alunos' => null], 'mod' => ['financeiro', 'avaliacao', 'x']])]);

        $direitos = $this->servico()->doTenantActual();

        // Vale o que está assinado no token, não o json editado.
        $this->assertSame(5, $direitos->limites['alunos']);
        $this->assertNotContains('x', $direitos->modulos);
    }

    public function test_token_valido_de_outra_escola_copiado_para_a_bd_nao_da_direitos(): void
    {
        $this->instalar($this->tokenDeTeste());
        DB::table('licenciamento_direitos')->update(['token' => $this->tokenDeTeste(['escola' => 'MOSI-999999', 'jti' => 'alheia'])]);

        $this->assertNull($this->servico()->doTenantActual());
    }

    public function test_chave_desconhecida_ou_invalida_na_config_e_ignorada(): void
    {
        $this->instalar($this->tokenDeTeste());
        config(['licenciamento.chaves_publicas' => ['lixo' => '!!!nao-base64!!!', 'curta' => base64_encode('abc')]]);

        $this->assertNull($this->servico()->doTenantActual());
    }

    public function test_isolamento_licenca_do_tenant_a_nao_e_vista_no_tenant_b(): void
    {
        $this->instalar($this->tokenDeTeste());
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($b, function () {
            $this->assertNull($this->servico()->doTenantActual());
            $this->assertSame(ModoDeOperacao::BLOQUEADO, $this->servico()->modo());
        });

        $this->assertSame('lic-1', $this->servico()->doTenantActual()?->id);
    }

    public function test_instalar_depois_de_consultar_actualiza_a_memoria_do_pedido(): void
    {
        $servico = app(DireitosService::class);
        $this->assertNull($servico->doTenantActual());

        $this->instalar($this->tokenDeTeste());

        $this->assertSame('lic-1', $servico->doTenantActual()?->id);
    }

    public function test_o_servico_e_refeito_por_ambito_e_ve_o_tenant_novo(): void
    {
        $this->instalar($this->tokenDeTeste());
        $this->assertSame('lic-1', app(DireitosService::class)->doTenantActual()?->id);

        // Como num worker de filas / Octane: o âmbito é esquecido e o contexto muda de tenant.
        app()->forgetScopedInstances();
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        app(TenantContext::class)->definir($b->paraTenantAtual());

        $this->assertNull(app(DireitosService::class)->doTenantActual());
        $this->assertSame(ModoDeOperacao::BLOQUEADO, app(DireitosService::class)->modo());
    }
}
