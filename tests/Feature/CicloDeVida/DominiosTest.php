<?php

namespace Tests\Feature\CicloDeVida;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Tenant\Actions\AdicionarDominioAction;
use Modules\Tenant\Actions\CriarTenantAction;
use Modules\Tenant\Actions\EncerrarTenantAction;
use Modules\Tenant\Actions\RemoverDominioAction;
use Modules\Tenant\DTO\CriarTenantDTO;
use Modules\Tenant\Enums\TipoDominio;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\DominioNaoRemovivel;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Services\ClassificadorDominio;
use Tests\TestCase;

class DominiosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.dominios_raiz' => ['mositec.ao']]);
    }

    private function adicionar(string $dominio): Domain
    {
        return app(AdicionarDominioAction::class)->executar($this->tenant, $dominio);
    }

    public function test_adicionar_subdominio_da_raiz_regista_tipo_subdominio_e_nao_principal(): void
    {
        $dominio = $this->adicionar('  ColegioABC.Mositec.AO. ');

        $this->assertSame('colegioabc.mositec.ao', $dominio->dominio);
        $this->assertSame(TipoDominio::SUBDOMINIO, $dominio->fresh()->tipo);
        $this->assertSame('Subdomínio', $dominio->fresh()->tipo_descricao);
        $this->assertFalse($dominio->fresh()->is_principal);
        $this->assertSame($this->tenant->id, $dominio->tenant_id);
    }

    public function test_adicionar_dominio_proprio_regista_tipo_personalizado(): void
    {
        $dominio = $this->adicionar('colegioabc.edu.ao');

        $this->assertSame(TipoDominio::PERSONALIZADO, $dominio->fresh()->tipo);
        $this->assertSame('Domínio personalizado', $dominio->fresh()->tipo_descricao);
    }

    public function test_o_dominio_adicionado_passa_a_resolver_o_tenant(): void
    {
        $this->adicionar('colegioabc.mositec.ao');

        $this->get('http://colegioabc.mositec.ao/login')->assertOk();
    }

    public function test_classificador(): void
    {
        config(['app.url' => 'http://mositec-escola.test']);
        $c = app(ClassificadorDominio::class);

        $this->assertSame(TipoDominio::SUBDOMINIO, $c->classificar('a.mositec.ao'));
        $this->assertSame(TipoDominio::SUBDOMINIO, $c->classificar('a.b.mositec.ao'));
        $this->assertSame(TipoDominio::SUBDOMINIO, $c->classificar('escola-b.mositec-escola.test'));
        $this->assertSame(TipoDominio::PERSONALIZADO, $c->classificar('mositec.ao'), 'A própria raiz não é subdomínio.');
        $this->assertSame(TipoDominio::PERSONALIZADO, $c->classificar('xmositec.ao'), 'Sufixo sem ponto não conta.');
        $this->assertSame(TipoDominio::PERSONALIZADO, $c->classificar('mositec.ao.evil.com'));
        $this->assertSame(TipoDominio::PERSONALIZADO, $c->classificar('colegio.edu.ao'));
    }

    public function test_recusa_invalido_reservado_central_e_duplicado(): void
    {
        config(['tenancy.hosts_centrais' => ['plataforma.mositec.ao']]);
        $this->adicionar('existente.mositec.ao');

        foreach (['não é domínio', 'www.mositec.ao', 'admin.escola.edu.ao', 'plataforma.mositec.ao', 'existente.mositec.ao', 'EXISTENTE.mositec.ao', 'localhost'] as $invalido) {
            try {
                $this->adicionar($invalido);
                $this->fail("Devia recusar '{$invalido}'.");
            } catch (DadosDeTenantInvalidos $e) {
                $this->assertArrayHasKey('dominio', $e->erros, $invalido);
            }
        }

        // 'localhost' é do tenant de teste (já registado): também duplicado.
        $this->assertSame(2, Domain::query()->count());
    }

    public function test_recusa_dominio_ja_registado_noutro_tenant(): void
    {
        $this->criarTenant('MOSI-000002', 'Escola B', 'b.mositec.ao');

        $this->expectException(DadosDeTenantInvalidos::class);

        $this->adicionar('b.mositec.ao');
    }

    public function test_nao_se_adiciona_dominio_a_tenant_encerrado(): void
    {
        app(EncerrarTenantAction::class)->executar($this->tenant);

        $this->expectException(TransicaoDeEstadoInvalida::class);

        $this->adicionar('novo.mositec.ao');
    }

    public function test_remover_dominio_secundario(): void
    {
        $this->adicionar('extra.mositec.ao');

        app(RemoverDominioAction::class)->executar($this->tenant, 'EXTRA.mositec.ao');

        $this->assertFalse(Domain::query()->where('dominio', 'extra.mositec.ao')->exists());
        $this->get('http://extra.mositec.ao/login')->assertNotFound();
        $this->assertTrue(Domain::query()->where('dominio', 'localhost')->exists());
    }

    public function test_nao_remove_o_dominio_principal_nem_o_ultimo(): void
    {
        $this->adicionar('extra.mositec.ao');

        try {
            app(RemoverDominioAction::class)->executar($this->tenant, 'localhost');
            $this->fail('Devia recusar remover o principal.');
        } catch (DominioNaoRemovivel $e) {
            $this->assertStringContainsString('principal', $e->getMessage());
        }

        $this->assertSame(2, $this->tenant->dominios()->count());
    }

    public function test_nao_remove_o_unico_dominio_mesmo_sem_principal(): void
    {
        $this->tenant->dominios()->update(['is_principal' => false]);

        $this->expectException(DominioNaoRemovivel::class);

        app(RemoverDominioAction::class)->executar($this->tenant, 'localhost');
    }

    public function test_nao_remove_dominio_de_outro_tenant_nem_inexistente(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'b.mositec.ao');
        $b->dominios()->create(['dominio' => 'b2.mositec.ao']);

        foreach (['b2.mositec.ao', 'nao-existe.mositec.ao'] as $dominio) {
            try {
                app(RemoverDominioAction::class)->executar($this->tenant, $dominio);
                $this->fail("Devia recusar '{$dominio}'.");
            } catch (DominioNaoRemovivel $e) {
                $this->assertStringContainsString('não pertence', $e->getMessage());
            }
        }

        $this->assertTrue(Domain::query()->where('dominio', 'b2.mositec.ao')->exists());
    }

    public function test_criar_tenant_classifica_o_dominio_principal(): void
    {
        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);

        foreach ([['MOSI-000010', 'novo.mositec.ao', TipoDominio::SUBDOMINIO], ['MOSI-000011', 'escola-propria.edu.ao', TipoDominio::PERSONALIZADO]] as [$codigo, $dominio, $tipo]) {
            $criado = app(CriarTenantAction::class)->executar(new CriarTenantDTO('Escola', 'Admin', "admin-{$codigo}@x.test", $dominio, $codigo));

            $this->assertSame($tipo, $criado->dominio->fresh()->tipo, $dominio);
            $this->assertTrue($criado->dominio->fresh()->is_principal);
        }
    }

    public function test_a_mensagem_de_adicionar_a_tenant_encerrado_e_gramatical(): void
    {
        app(EncerrarTenantAction::class)->executar($this->tenant);

        try {
            $this->adicionar('novo.mositec.ao');
            $this->fail('Devia recusar.');
        } catch (TransicaoDeEstadoInvalida $e) {
            $this->assertSame('Não é possível adicionar um domínio (tenant MOSI-000001): estado actual Encerrado. O estado Encerrado é terminal; reabrir é um procedimento manual.', $e->getMessage());
        }
    }

    public function test_nao_se_remove_dominio_de_tenant_encerrado(): void
    {
        $this->adicionar('extra.mositec.ao');
        app(EncerrarTenantAction::class)->executar($this->tenant);

        try {
            app(RemoverDominioAction::class)->executar($this->tenant, 'extra.mositec.ao');
            $this->fail('Devia recusar.');
        } catch (DominioNaoRemovivel $e) {
            $this->assertStringContainsString('Encerrado', $e->getMessage());
            $this->assertStringContainsString('reservados', $e->getMessage());
        }

        $this->assertTrue(Domain::query()->where('dominio', 'extra.mositec.ao')->exists());
    }
}
