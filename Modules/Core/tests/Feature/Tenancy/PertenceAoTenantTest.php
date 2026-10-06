<?php

namespace Modules\Core\Tests\Feature\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use Tests\TestCase;

class ItemDeTeste extends Model
{
    use PertenceAoTenant;

    protected $table = 'tenancy_teste_itens';

    protected $fillable = ['nome'];

    public $timestamps = false;
}

class PertenceAoTenantTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $contexto;

    private TenantAtual $tenantA;

    private TenantAtual $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tenancy_teste_itens');
        Schema::create('tenancy_teste_itens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('nome');
        });

        $this->tenantA = new TenantAtual(101, 'MOSI-000101', 'Escola A', EstadoTenant::ACTIVO);
        $this->tenantB = new TenantAtual(102, 'MOSI-000102', 'Escola B', EstadoTenant::ACTIVO);

        $this->contexto = app(TenantContext::class);
        $this->contexto->limpar();
    }

    public function test_criacao_preenche_o_tenant_id_a_partir_do_contexto(): void
    {
        $item = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::create(['nome' => 'a1']));

        $this->assertSame(101, (int) DB::table('tenancy_teste_itens')->where('id', $item->id)->value('tenant_id'));
    }

    public function test_leitura_so_devolve_registos_do_tenant_corrente(): void
    {
        $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::create(['nome' => 'a1']));
        $this->contexto->executarComo($this->tenantB, fn () => ItemDeTeste::create(['nome' => 'b1']));

        $nomes = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::query()->pluck('nome')->all());

        $this->assertSame(['a1'], $nomes);
    }

    public function test_find_de_um_id_de_outro_tenant_devolve_null(): void
    {
        $deB = $this->contexto->executarComo($this->tenantB, fn () => ItemDeTeste::create(['nome' => 'b1']));

        $encontrado = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::find($deB->id));

        $this->assertNull($encontrado);
    }

    public function test_leitura_sem_tenant_lanca_excepcao(): void
    {
        $this->expectException(TenantNaoResolvido::class);

        ItemDeTeste::query()->get();
    }

    public function test_criacao_sem_tenant_lanca_excepcao(): void
    {
        $this->expectException(TenantNaoResolvido::class);

        ItemDeTeste::create(['nome' => 'orfao']);
    }

    public function test_criacao_com_tenant_id_de_outro_tenant_lanca_excepcao(): void
    {
        $this->expectException(AlteracaoDeTenantProibida::class);

        $this->contexto->executarComo($this->tenantA, function () {
            $item = new ItemDeTeste(['nome' => 'forjado']);
            $item->tenant_id = 102;
            $item->save();
        });
    }

    public function test_alterar_o_tenant_id_lanca_excepcao(): void
    {
        $this->expectException(AlteracaoDeTenantProibida::class);

        $this->contexto->executarComo($this->tenantA, function () {
            $item = ItemDeTeste::create(['nome' => 'a1']);
            $item->tenant_id = 102;
            $item->save();
        });
    }

    public function test_alterar_sem_tenant_lanca_excepcao(): void
    {
        $item = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::create(['nome' => 'a1']));

        $this->expectException(TenantNaoResolvido::class);

        $item->update(['nome' => 'alterado']);
    }

    public function test_apagar_sem_tenant_lanca_excepcao(): void
    {
        $item = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::create(['nome' => 'a1']));

        $this->expectException(TenantNaoResolvido::class);

        $item->delete();
    }

    public function test_alterar_um_registo_de_outro_tenant_lanca_excepcao_e_nao_grava(): void
    {
        $item = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::create(['nome' => 'a1']));

        try {
            $this->contexto->executarComo($this->tenantB, fn () => $item->update(['nome' => 'alterado por B']));
            $this->fail('Devia ter lançado AlteracaoDeTenantProibida.');
        } catch (AlteracaoDeTenantProibida) {
        }

        $this->assertSame('a1', DB::table('tenancy_teste_itens')->where('id', $item->id)->value('nome'));
    }

    public function test_apagar_um_registo_de_outro_tenant_lanca_excepcao_e_nao_apaga(): void
    {
        $item = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::create(['nome' => 'a1']));

        try {
            $this->contexto->executarComo($this->tenantB, fn () => $item->delete());
            $this->fail('Devia ter lançado AlteracaoDeTenantProibida.');
        } catch (AlteracaoDeTenantProibida) {
        }

        $this->assertSame(1, DB::table('tenancy_teste_itens')->where('id', $item->id)->count());
    }
}
