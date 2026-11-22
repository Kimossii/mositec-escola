<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class HorarioAutorizacaoTest extends TestCase
{
    use CenarioDeHorario;
    use RefreshDatabase;

    private array $c;

    private GrelhaEntrada $entrada;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->c = $this->cenarioDeHorario();
        $this->entrada = $this->criarEntrada($this->c, ['vigencia_inicio' => '2026-04-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function como(Perfil $perfil): User
    {
        $u = User::create(['name' => $perfil->value, 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);
        $this->actingAs($u);

        return $u;
    }

    /** @return array<string, array{0: string, 1: string, 2: array}> */
    private function pedidos(): array
    {
        $c = $this->c;
        $corpo = [
            'turma_id' => $c['turma']->id, 'dia_semana' => 3, 'horario_id' => $c['tempo2']->id,
            'disciplina_id' => $c['disciplina']->id, 'vigencia_inicio' => '2026-04-01',
        ];

        return [
            'index' => ['get', route('grelha.index'), []],
            'store' => ['post', route('grelha.store'), $corpo],
            'update' => ['put', route('grelha.update', $this->entrada), $corpo],
            'encerrar' => ['patch', route('grelha.encerrar', $this->entrada), ['vigencia_fim' => '2026-06-30']],
            'destroy' => ['delete', route('grelha.destroy', $this->entrada), []],
        ];
    }

    public function test_professor_aluno_e_encarregado_recebem_403_na_gestao(): void
    {
        foreach ([Perfil::PROFESSOR, Perfil::ALUNO, Perfil::ENCARREGADO] as $perfil) {
            $this->como($perfil);

            foreach ($this->pedidos() as $nome => [$metodo, $url, $corpo]) {
                $this->{$metodo}($url, $corpo)->assertForbidden("{$perfil->value} em {$nome}");
            }
        }

        $this->assertNotNull($this->entrada->fresh());
    }

    public function test_funcionario_nao_elimina_mas_faz_o_resto(): void
    {
        $this->como(Perfil::FUNCIONARIO);

        $this->get(route('grelha.index'))->assertOk();
        $this->post(route('grelha.store'), $this->pedidos()['store'][2])->assertSessionHasNoErrors();
        $this->patch(route('grelha.encerrar', $this->entrada), ['vigencia_fim' => '2026-06-30'])->assertSessionHasNoErrors();
        $this->delete(route('grelha.destroy', $this->entrada))->assertForbidden();
    }

    public function test_admin_escola_elimina(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->delete(route('grelha.destroy', $this->entrada))->assertSessionHasNoErrors();
        $this->assertNull(GrelhaEntrada::find($this->entrada->id));
    }

    public function test_meu_horario_exige_area_professor_e_aluno_nao_entra(): void
    {
        $this->como(Perfil::ALUNO);
        $this->get(route('area-professor.horario'))->assertForbidden();

        $this->como(Perfil::PROFESSOR);
        $this->get(route('area-professor.horario'))->assertOk();
    }

    public function test_entrada_de_outro_tenant_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $deB = $this->noTenant($outro, fn () => $this->criarEntrada($this->cenarioDeHorario(), ['vigencia_inicio' => '2026-04-01']));
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->delete(route('grelha.destroy', $deB->id))->assertNotFound();
        $this->put(route('grelha.update', $deB->id), $this->pedidos()['update'][2])->assertNotFound();
    }

    public function test_anonimo_e_redireccionado_para_login(): void
    {
        foreach ($this->pedidos() as [$metodo, $url, $corpo]) {
            $this->{$metodo}($url, $corpo)->assertRedirect();
        }
    }
}
