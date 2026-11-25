<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class HorarioHttpTest extends TestCase
{
    use CenarioDeHorario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($admin);

        return $admin;
    }

    private function payload(array $c, array $extra = []): array
    {
        return array_merge([
            'turma_id' => $c['turma']->id, 'dia_semana' => 1, 'horario_id' => $c['tempo1']->id,
            'disciplina_id' => $c['disciplina']->id, 'sala_id' => $c['sala']->id, 'vigencia_inicio' => '2026-03-02',
        ], $extra);
    }

    public function test_index_por_turma_devolve_props(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c);
        $this->actingAsAdmin();

        $this->get(route('grelha.index', ['turma_id' => $c['turma']->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Horario/Grelha/Index')
                ->where('vista', 'turma')
                ->where('data', '2026-03-02')
                ->has('grelha.tempos', 3)
                ->has('grelha.entradas', 1)
                ->has('grelha.disciplinas', 2)
                ->has('opcoes.turmas', 1)
                ->has('opcoes.salas', 1)
                ->has('opcoes.docentes', 1)
                ->has('filtros'));
    }

    public function test_index_sem_escolha_nao_calcula_grelha_e_vista_invalida_cai_em_turma(): void
    {
        $this->cenarioDeHorario();
        $this->actingAsAdmin();

        $this->get(route('grelha.index', ['vista' => 'qualquer']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('vista', 'turma')->where('grelha', null));
    }

    public function test_index_por_docente_e_por_sala(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c, ['sala_id' => $c['sala']->id]);
        $this->actingAsAdmin();

        $this->get(route('grelha.index', ['vista' => 'docente', 'docente_id' => $c['docente']->id]))
            ->assertInertia(fn (Assert $page) => $page->where('vista', 'docente')->has('grelha.entradas', 1));
        $this->get(route('grelha.index', ['vista' => 'sala', 'sala_id' => $c['sala']->id]))
            ->assertInertia(fn (Assert $page) => $page->where('vista', 'sala')->has('grelha.entradas', 1));
    }

    public function test_store_valido_redirecciona_com_success(): void
    {
        $c = $this->cenarioDeHorario();
        $this->actingAsAdmin();

        $this->post(route('grelha.store'), $this->payload($c))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(1, GrelhaEntrada::count());
    }

    public function test_store_com_conflito_devolve_erro_de_validacao(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c, ['vigencia_inicio' => '2026-03-02']);
        $this->actingAsAdmin();

        $this->post(route('grelha.store'), $this->payload($c, ['disciplina_id' => $c['outraDisciplina']->id]))
            ->assertSessionHasErrors(['horario_id']);

        $this->assertSame(1, GrelhaEntrada::count());
    }

    public function test_store_recusa_dia_invalido_e_ids_de_outro_tenant(): void
    {
        $c = $this->cenarioDeHorario();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $turmaB = $this->noTenant($outro, fn () => $this->cenarioDeDocencia()['turma']);
        $this->actingAsAdmin();

        $this->post(route('grelha.store'), $this->payload($c, ['dia_semana' => 7, 'turma_id' => $turmaB->id]))
            ->assertSessionHasErrors(['dia_semana', 'turma_id']);
    }

    public function test_update_cria_nova_versao(): void
    {
        $c = $this->cenarioDeHorario();
        $entrada = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01']);
        $this->actingAsAdmin();

        $this->put(route('grelha.update', $entrada), $this->payload($c, ['disciplina_id' => $c['outraDisciplina']->id, 'vigencia_inicio' => '2026-03-10']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-03-09', $entrada->fresh()->vigencia_fim->toDateString());
        $this->assertSame(2, GrelhaEntrada::count());
    }

    public function test_encerrar_e_destroy(): void
    {
        $c = $this->cenarioDeHorario();
        $aEncerrar = $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01']);
        $futura = $this->criarEntrada($c, ['dia_semana' => 2, 'vigencia_inicio' => '2026-04-01']);
        $this->actingAsAdmin();

        $this->patch(route('grelha.encerrar', $aEncerrar), ['vigencia_fim' => '2026-06-30'])->assertSessionHasNoErrors();
        $this->delete(route('grelha.destroy', $futura))->assertSessionHasNoErrors();

        $this->assertSame('2026-06-30', $aEncerrar->fresh()->vigencia_fim->toDateString());
        $this->assertNull(GrelhaEntrada::find($futura->id));
        $this->delete(route('grelha.destroy', $aEncerrar))->assertSessionHasErrors(['entrada']);
    }

    public function test_meu_horario_so_mostra_as_aulas_do_professor(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c, ['disciplina_id' => $c['disciplina']->id]);
        $professor = $this->criarDocente('Professor Português', comAcesso: true);
        $this->atribuirTitular($c, $professor, $c['outraDisciplina']->id);
        $this->criarEntrada($c, ['dia_semana' => 2, 'disciplina_id' => $c['outraDisciplina']->id]);

        $this->actingAs($professor->utilizador());

        $this->get(route('area-professor.horario'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Horario/MeuHorario')
                ->where('data', '2026-03-02')
                ->has('grelha.entradas', 1)
                ->where('grelha.entradas.0.disciplina_nome', 'Português'));
    }
}
