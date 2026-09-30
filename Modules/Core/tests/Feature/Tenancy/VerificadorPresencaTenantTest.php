<?php

namespace Modules\Core\Tests\Feature\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Tenancy\Validation\VerificadorPresencaTenant;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class VerificadorPresencaTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantB;

    private int $idDeA;

    private int $idDeB;

    protected function setUp(): void
    {
        parent::setUp();

        // Tabela de tenant: não consta em nenhuma lista de config/tenancy.php.
        Schema::dropIfExists('tenancy_teste_itens');
        Schema::create('tenancy_teste_itens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('codigo');
            $table->string('grupo')->nullable();
        });

        // Tabela global: sem tenant_id, declarada em tabelas_globais.
        Schema::dropIfExists('tenancy_teste_globais');
        Schema::create('tenancy_teste_globais', function (Blueprint $table) {
            $table->id();
            $table->string('codigo');
        });
        config(['tenancy.tabelas_globais' => [...config('tenancy.tabelas_globais'), 'tenancy_teste_globais']]);

        $this->tenantB = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');

        $this->idDeA = DB::table('tenancy_teste_itens')->insertGetId(['tenant_id' => $this->tenant->id, 'codigo' => 'A-1', 'grupo' => 'x']);
        $this->idDeB = DB::table('tenancy_teste_itens')->insertGetId(['tenant_id' => $this->tenantB->id, 'codigo' => 'B-1', 'grupo' => 'x']);
        DB::table('tenancy_teste_globais')->insert(['codigo' => 'G-1']);
    }

    private function passa(array $dados, array $regras): bool
    {
        return Validator::make($dados, $regras)->passes();
    }

    public function test_o_container_usa_o_verificador_de_tenancy(): void
    {
        $this->assertInstanceOf(VerificadorPresencaTenant::class, app('validation.presence'));
    }

    public function test_exists_aceita_um_id_do_tenant_corrente(): void
    {
        $this->assertTrue($this->passa(['id' => $this->idDeA], ['id' => 'exists:tenancy_teste_itens,id']));
    }

    public function test_exists_rejeita_um_id_de_outro_tenant(): void
    {
        $this->assertFalse($this->passa(['id' => $this->idDeB], ['id' => 'exists:tenancy_teste_itens,id']));
    }

    public function test_exists_segue_o_contexto_e_nao_um_tenant_fixo(): void
    {
        $passaEmB = $this->noTenant($this->tenantB, fn () => $this->passa(['id' => $this->idDeB], ['id' => 'exists:tenancy_teste_itens,id']));

        $this->assertTrue($passaEmB);
    }

    public function test_exists_com_lista_que_mistura_tenants_falha(): void
    {
        $regras = ['ids' => 'array', 'ids.*' => 'exists:tenancy_teste_itens,id'];

        $this->assertFalse($this->passa(['ids' => [$this->idDeA, $this->idDeB]], $regras));
        $this->assertTrue($this->passa(['ids' => [$this->idDeA]], $regras));
    }

    public function test_exists_com_array_de_valores_num_so_campo_conta_so_os_do_tenant(): void
    {
        // Um campo cujo valor é um array faz o Laravel usar getMultiCount().
        $regras = ['ids' => 'exists:tenancy_teste_itens,id'];

        $this->assertFalse($this->passa(['ids' => [$this->idDeA, $this->idDeB]], $regras));
        $this->assertTrue($this->passa(['ids' => [$this->idDeA]], $regras));
    }

    public function test_unique_permite_o_mesmo_valor_noutro_tenant(): void
    {
        $this->assertTrue($this->passa(['codigo' => 'B-1'], ['codigo' => 'unique:tenancy_teste_itens,codigo']));
    }

    public function test_unique_rejeita_duplicado_no_mesmo_tenant(): void
    {
        $this->assertFalse($this->passa(['codigo' => 'A-1'], ['codigo' => 'unique:tenancy_teste_itens,codigo']));
    }

    public function test_unique_com_ignore_continua_a_funcionar(): void
    {
        $regra = Rule::unique('tenancy_teste_itens', 'codigo')->ignore($this->idDeA);

        $this->assertTrue($this->passa(['codigo' => 'A-1'], ['codigo' => $regra]));
    }

    public function test_condicoes_adicionais_continuam_a_funcionar(): void
    {
        $noGrupoX = Rule::unique('tenancy_teste_itens', 'codigo')->where('grupo', 'x');
        $noGrupoY = Rule::unique('tenancy_teste_itens', 'codigo')->where('grupo', 'y');

        $this->assertFalse($this->passa(['codigo' => 'A-1'], ['codigo' => $noGrupoX]));
        $this->assertTrue($this->passa(['codigo' => 'A-1'], ['codigo' => $noGrupoY]));
    }

    public function test_tabela_global_nao_e_filtrada(): void
    {
        $this->assertTrue($this->passa(['codigo' => 'G-1'], ['codigo' => 'exists:tenancy_teste_globais,codigo']));
    }

    public function test_tabela_global_funciona_sem_tenant(): void
    {
        app(TenantContext::class)->limpar();

        $this->assertTrue($this->passa(['codigo' => 'G-1'], ['codigo' => 'exists:tenancy_teste_globais,codigo']));
    }

    public function test_tabela_de_tenant_sem_contexto_lanca_excepcao(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        $this->passa(['id' => $this->idDeA], ['id' => 'exists:tenancy_teste_itens,id']);
    }

    public function test_tabela_por_converter_nao_e_filtrada(): void
    {
        // users ainda não tem tenant_id: filtrar daria erro de coluna inexistente.
        $this->assertTrue($this->passa(['email' => 'livre@exemplo.ao'], ['email' => 'unique:users,email']));
    }
}
