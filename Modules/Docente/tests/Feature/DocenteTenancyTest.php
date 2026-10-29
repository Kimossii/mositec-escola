<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocenteTenancyTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    private function administrador(): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'admin-a@example.com', 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    /** Docente com uma atribuição, criados no tenant B. */
    private function dadosDeB(): array
    {
        return $this->noTenant($this->outro, function () {
            $c = $this->cenarioDeDocencia();
            $docente = $this->criarDocente('Docente Em B');
            $atribuicao = AtribuicaoDocente::create([
                'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
                'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01', 'data_fim' => null,
            ]);

            return compact('docente', 'atribuicao');
        });
    }

    public function test_a_listagem_de_docentes_mostra_so_os_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->criarDocente('Docente So Em A');
        $this->dadosDeB();

        $conteudo = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/docentes'))->assertOk()->getContent();

        $this->assertStringContainsString('Docente So Em A', $conteudo);
        $this->assertStringNotContainsString('Docente Em B', $conteudo);
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $b = $this->dadosDeB();

        $this->actingAs($admin)->put($this->urlDoTenant($this->tenant, "/docentes/{$b['docente']->id}"), ['nome_completo' => 'Invadido'])->assertNotFound();
        $this->actingAs($admin)->patch($this->urlDoTenant($this->tenant, "/docentes/{$b['docente']->id}/estado"))->assertNotFound();
        $this->actingAs($admin)->delete($this->urlDoTenant($this->tenant, "/docentes/{$b['docente']->id}"))->assertNotFound();
        $this->actingAs($admin)->delete($this->urlDoTenant($this->tenant, "/docentes/atribuicoes/{$b['atribuicao']->id}"))->assertNotFound();

        $this->noTenant($this->outro, function () use ($b) {
            $this->assertNotNull(Docente::find($b['docente']->id));
            $this->assertNotNull(AtribuicaoDocente::find($b['atribuicao']->id));
        });
    }

    public function test_sem_contexto_de_tenant_ler_docentes_e_atribuicoes_lanca(): void
    {
        app(TenantContext::class)->limpar();

        foreach ([Docente::class, AtribuicaoDocente::class] as $classe) {
            try {
                $classe::query()->count();
                $this->fail("{$classe}: leu sem contexto de tenant.");
            } catch (TenantNaoResolvido) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_criar_docente_com_tenant_id_de_outra_escola_e_proibido(): void
    {
        $docente = $this->criarDocente('Docente A');

        $this->expectException(AlteracaoDeTenantProibida::class);

        (new Docente())->forceFill([
            'tenant_id' => $this->outro->id, 'estabelecimento_id' => $docente->estabelecimento_id, 'dados_pessoa_id' => $docente->dados_pessoa_id,
        ])->save();
    }
}
