<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\ConsultaFaltasDoAluno;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class FrequenciaTenancyTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function administrador(): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'admin-a@example.com', 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    public function test_pedir_no_dominio_de_a_uma_aula_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $idDeB = $this->noTenant($this->outro, fn () => $this->aulaComRegistos($this->cenarioDeFrequencia(), '2026-03-02'))->id;

        $this->actingAs($admin)->patch($this->urlDoTenant($this->tenant, "/frequencia/aulas/{$idDeB}/nao-leccionada"), ['motivo' => 'x'])->assertNotFound();
        $this->assertNotNull($this->noTenant($this->outro, fn () => Aula::find($idDeB)));
    }

    public function test_abrir_chamada_de_turma_de_outro_tenant_recusado(): void
    {
        $cB = $this->noTenant($this->outro, fn () => $this->cenarioDeFrequencia());
        $admin = $this->administrador();

        $this->expectException(ValidationException::class);

        app(AbrirChamadaAction::class)->executar($admin, $cB['turma']->id, '2026-03-02', $cB['tempo1']->id);
    }

    public function test_resumo_de_faltas_de_matricula_de_b_no_contexto_de_a_e_zeros(): void
    {
        $cB = $this->noTenant($this->outro, fn () => $this->cenarioDeFrequencia());
        $this->noTenant($this->outro, fn () => $this->aulaComRegistos($cB, '2026-03-02', [EstadoPresenca::FALTA]));

        $deA = app(ConsultaFaltasDoAluno::class)->resumoDaMatricula($cB['alunos'][0]->id);
        $deB = $this->noTenant($this->outro, fn () => app(ConsultaFaltasDoAluno::class)->resumoDaMatricula($cB['alunos'][0]->id));

        $this->assertSame([0, false], [$deA['total'], $deA['com_chamadas']]);
        $this->assertSame([1, true], [$deB['total'], $deB['com_chamadas']]);
    }

    public function test_alterar_o_tenant_de_uma_aula_lanca_excepcao(): void
    {
        $aula = $this->aulaComRegistos($this->cenarioDeFrequencia(), '2026-03-02')->fresh();
        $aula->tenant_id = $this->outro->id;

        $this->expectException(AlteracaoDeTenantProibida::class);

        $aula->save();
    }

    public function test_sem_contexto_a_leitura_lanca(): void
    {
        $this->aulaComRegistos($this->cenarioDeFrequencia(), '2026-03-02');
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Registo::query()->get();
    }

    public function test_a_base_recusa_referencias_a_dados_de_outro_tenant(): void
    {
        $cA = $this->cenarioDeFrequencia();
        $cB = $this->noTenant($this->outro, fn () => $this->cenarioDeFrequencia());

        foreach (['horario_id' => $cB['tempo1']->id, 'docente_id' => $cB['docente']->id, 'sala_id' => $cB['sala']->id, 'disciplina_id' => $cB['disciplina']->id] as $campo => $idDeB) {
            try {
                $this->aulaComRegistos($cA, '2026-03-02', [], [$campo => $idDeB]);
                $this->fail("A base aceitou {$campo} de outro tenant.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
