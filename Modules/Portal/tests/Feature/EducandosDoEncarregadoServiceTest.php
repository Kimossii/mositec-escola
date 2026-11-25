<?php

namespace Modules\Portal\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Portal\Services\EducandosDoEncarregadoService;
use Modules\Portal\Tests\Support\CenarioDeFamilia;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EducandosDoEncarregadoServiceTest extends TestCase
{
    use CenarioDeFamilia;
    use RefreshDatabase;

    private EducandosDoEncarregadoService $servico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenario();
        $this->servico = app(EducandosDoEncarregadoService::class);
    }

    public function test_lista_so_os_educandos_activos_do_encarregado_por_ordem_de_nome(): void
    {
        $enc = $this->criarEncarregado();
        $this->ligar($enc, $this->criarEducando('Zeca', '2026-0001'), 'Pai');
        $this->ligar($enc, $this->criarEducando('Ana', '2026-0002'), 'Mãe');
        $this->ligar($enc, $this->criarEducando('Inactivo', '2026-0003', estado: 0));
        $outro = $this->criarEncarregado('outro@example.com');
        $this->ligar($outro, $this->criarEducando('De outro', '2026-0004'));

        $lista = $this->servico->listar($enc);

        $this->assertSame(['Ana', 'Zeca'], $lista->pluck('name')->all());
        $this->assertSame('Mãe', $lista->first()->pivot->parentesco);
    }

    public function test_obter_devolve_o_educando_ligado_e_null_para_os_outros(): void
    {
        $enc = $this->criarEncarregado();
        $filho = $this->criarEducando('Filho', '2026-0001');
        $this->ligar($enc, $filho);
        $inactivo = $this->criarEducando('Inactivo', '2026-0002', estado: 0);
        $this->ligar($enc, $inactivo);
        $alheio = $this->criarEducando('Alheio', '2026-0003');
        $this->ligar($this->criarEncarregado('outro@example.com'), $alheio);

        $this->assertSame($filho->id, $this->servico->obter($enc, $filho->id)?->id);
        $this->assertNull($this->servico->obter($enc, $alheio->id));
        $this->assertNull($this->servico->obter($enc, 999999));
        $this->assertNull($this->servico->obter($enc, $inactivo->id));
    }

    public function test_ligacao_de_outra_escola_nao_aparece(): void
    {
        $enc = $this->criarEncarregado();
        $outra = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idDeB = $this->noTenant($outra, fn () => User::create(['name' => 'Aluno B', 'numero_matricula' => '2026-9999', 'password' => Hash::make('x')])->id);

        DB::table('encarregados_alunos')->insert([
            'tenant_id' => $outra->id, 'encarregado_id' => $enc->id, 'aluno_id' => $idDeB, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertCount(0, $this->servico->listar($enc));
        $this->assertNull($this->servico->obter($enc, $idDeB));
    }

    public function test_o_encarregado_sem_educandos_recebe_lista_vazia(): void
    {
        $this->assertCount(0, $this->servico->listar($this->criarEncarregado()));
    }
}
