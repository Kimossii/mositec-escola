<?php

namespace Modules\Docente\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtribuicaoDocenteHttpTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'));
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

    private function payload(array $c, Docente $prof, array $extra = []): array
    {
        return array_merge([
            'docente_id' => $prof->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'papel' => PapelDocente::TITULAR->value, 'data_inicio' => '2026-06-15',
        ], $extra);
    }

    private function existente(array $c, Docente $prof, string $inicio, ?string $fim = null): AtribuicaoDocente
    {
        return AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $prof->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => $inicio, 'data_fim' => $fim,
        ]);
    }

    public function test_index_renderiza_inertia_com_props(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->criarDocente();
        $this->actingAsAdmin();

        $this->get(route('atribuicoes-docentes.index', ['turma_id' => $c['turma']->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Docente/Atribuicoes/Index')
                ->where('anoLectivoId', $c['ano']->id)
                ->where('vista', 'turma')
                ->has('porTurma.linhas', 2)
                ->has('atribuicoes.data', 0)
                ->has('filtros')
                ->has('opcoes.anosLectivos')
                ->has('opcoes.turmas', 1)
                ->has('opcoes.docentes', 1)
                ->where('opcoes.docentes.0.tem_acesso', false)
                ->missing('opcoes.professores')
                ->has('opcoes.disciplinas', 0)
                ->missing('indicadores')
                ->loadDeferredProps(fn (Assert $deferred) => $deferred
                    ->where('indicadores.em_vigor', 0)
                    ->where('indicadores.disciplinas_sem_titular', 2)));
    }

    public function test_index_sem_ano_activo_usa_o_ano_mais_recente(): void
    {
        $c = $this->cenarioDeDocencia();
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        $novo = AnoLectivo::create([
            'estabelecimento_id' => $c['ano']->estabelecimento_id, 'nome' => '2027', 'data_inicio' => '2027-01-01',
            'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::PLANEADO,
        ]);
        $this->existente($c, $this->criarDocente(), '2026-03-01');
        $this->actingAsAdmin();

        $this->get(route('atribuicoes-docentes.index', ['vista' => 'lista']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('anoLectivoId', $novo->id)->has('atribuicoes.data', 0));
    }

    public function test_index_filtra_a_lista_por_disciplina(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->existente($c, $this->criarDocente(), '2026-03-01');
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['outraDisciplina']->id,
            'docente_id' => $this->criarDocente('Docente b')->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01',
        ]);
        $this->actingAsAdmin();

        $this->get(route('atribuicoes-docentes.index', ['vista' => 'lista', 'disciplina_id' => $c['outraDisciplina']->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('opcoes.disciplinas', 2)
                ->where('filtros.disciplina_id', (string) $c['outraDisciplina']->id)
                ->has('atribuicoes.data', 1)
                ->where('atribuicoes.data.0.disciplina_id', $c['outraDisciplina']->id));
    }

    public function test_index_sem_turma_escolhida_nao_calcula_por_turma(): void
    {
        $this->cenarioDeDocencia();
        $this->actingAsAdmin();

        $this->get(route('atribuicoes-docentes.index', ['vista' => 'lista']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('vista', 'lista')->where('porTurma', null));
    }

    public function test_store_cria_e_regista_autoria(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $admin = $this->actingAsAdmin();

        $this->post(route('atribuicoes-docentes.store'), $this->payload($c, $prof))
            ->assertSessionHasNoErrors()->assertRedirect();

        $a = AtribuicaoDocente::firstOrFail();
        $this->assertSame($admin->id, $a->criado_por);
        $this->assertSame($c['ano']->id, $a->ano_lectivo_id);
        $this->assertSame('Titular', $a->papel_descricao);
    }

    public function test_store_devolve_erros_de_validacao_em_portugues(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $this->actingAsAdmin();

        $this->post(route('atribuicoes-docentes.store'), [])
            ->assertSessionHasErrors(['docente_id', 'turma_id', 'disciplina_id', 'papel', 'data_inicio']);
        $this->assertSame('O docente é obrigatório.', session('errors')->first('docente_id'));

        $this->post(route('atribuicoes-docentes.store'), $this->payload($c, $prof, ['data_inicio' => '15/06/2026']))
            ->assertSessionHasErrors(['data_inicio']);

        // Erro de negócio da Action chega como erro de campo.
        $this->post(route('atribuicoes-docentes.store'), $this->payload($c, $prof, ['data_inicio' => '2027-02-01']))
            ->assertSessionHasErrors(['data_inicio']);
        $this->assertSame(0, AtribuicaoDocente::count());
    }

    public function test_substituir_encerrar_actualizar_eliminar(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarDocente('Docente a');
        $b = $this->criarDocente('Docente b');
        $this->actingAsAdmin();
        $titular = $this->existente($c, $a, '2026-01-01');

        // Substituir.
        $this->post(route('atribuicoes-docentes.substituir'), [
            'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'novo_docente_id' => $b->id,
            'ultimo_dia_do_titular' => '2026-06-30', 'motivo' => 'Licença',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('2026-06-30', $titular->fresh()->data_fim->toDateString());
        $novo = AtribuicaoDocente::where('docente_id', $b->id)->firstOrFail();
        $this->assertSame('2026-07-01', $novo->data_inicio->toDateString());

        // Substituir sem motivo.
        $this->post(route('atribuicoes-docentes.substituir'), [
            'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'novo_docente_id' => $b->id,
            'ultimo_dia_do_titular' => '2026-06-30',
        ])->assertSessionHasErrors(['motivo']);

        // Actualizar (a nova está agendada).
        $this->put(route('atribuicoes-docentes.update', $novo), [
            'papel' => PapelDocente::TITULAR->value, 'data_inicio' => '2026-07-05', 'data_fim' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame('2026-07-05', $novo->fresh()->data_inicio->toDateString());

        // Encerrar (a nova, agendada, ainda não começou: fim >= início).
        $this->patch(route('atribuicoes-docentes.encerrar', $novo), ['data_fim' => '2026-08-31', 'motivo' => 'Saída'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-08-31', $novo->fresh()->data_fim->toDateString());
        $this->assertSame('Saída', $novo->fresh()->motivo_encerramento);

        $this->patch(route('atribuicoes-docentes.encerrar', $novo), ['data_fim' => '2026-08-30'])
            ->assertSessionHasErrors(['motivo']);

        // Eliminar a agendada.
        $this->delete(route('atribuicoes-docentes.destroy', $novo))->assertSessionHasNoErrors();
        $this->assertNull(AtribuicaoDocente::find($novo->id));
    }

    public function test_ano_lectivo_encerrado_le_mas_nao_escreve(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $this->actingAsAdmin();
        $existente = $this->existente($c, $prof, '2026-01-01');
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);

        $this->get(route('atribuicoes-docentes.index', ['ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('porTurma.linhas', 2));

        $this->post(route('atribuicoes-docentes.store'), $this->payload($c, $prof, ['papel' => PapelDocente::SUBSTITUTO->value]))
            ->assertSessionHasErrors(['turma_id']);
        $this->patch(route('atribuicoes-docentes.encerrar', $existente), ['data_fim' => '2026-06-30', 'motivo' => 'x'])
            ->assertSessionHasErrors(['turma_id']);
        $this->assertSame(1, AtribuicaoDocente::count());
        $this->assertNull($existente->fresh()->data_fim);
    }

    public function test_ids_de_outro_tenant_em_turma_disciplina_e_professor_dao_erro_de_validacao_ou_404(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();

        $outro = $this->criarTenant('MOSI-000002', 'Outra Escola', 'outra.test');
        [$turmaB, $discB, $profB, $atribB] = $this->noTenant($outro, function () {
            $cb = $this->cenarioDeDocencia();
            $pb = $this->criarDocente('Docente prof-b');
            $ab = AtribuicaoDocente::create([
                'ano_lectivo_id' => $cb['ano']->id, 'turma_id' => $cb['turma']->id, 'disciplina_id' => $cb['disciplina']->id,
                'docente_id' => $pb->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-01-01',
            ]);

            return [$cb['turma'], $cb['disciplina'], $pb, $ab];
        });

        $this->actingAsAdmin();

        $this->post(route('atribuicoes-docentes.store'), $this->payload($c, $prof, ['turma_id' => $turmaB->id]))
            ->assertSessionHasErrors(['turma_id']);
        $this->post(route('atribuicoes-docentes.store'), $this->payload($c, $prof, ['disciplina_id' => $discB->id]))
            ->assertSessionHasErrors(['disciplina_id']);
        $this->post(route('atribuicoes-docentes.store'), $this->payload($c, $profB))
            ->assertSessionHasErrors(['docente_id']);
        $this->post(route('atribuicoes-docentes.substituir'), [
            'turma_id' => $turmaB->id, 'disciplina_id' => $discB->id, 'novo_docente_id' => $profB->id,
            'ultimo_dia_do_titular' => '2026-06-30', 'motivo' => 'x',
        ])->assertSessionHasErrors(['turma_id', 'disciplina_id', 'novo_docente_id']);

        // Atribuição de outro tenant: 404, nunca dados dessa escola.
        $this->delete(route('atribuicoes-docentes.destroy', $atribB->id))->assertNotFound();
        $this->patch(route('atribuicoes-docentes.encerrar', $atribB->id), ['data_fim' => '2026-06-30', 'motivo' => 'x'])->assertNotFound();

        // Leitura com ids de outro tenant: sem dados nem 500.
        $this->get(route('atribuicoes-docentes.index', ['turma_id' => $turmaB->id, 'ano_lectivo_id' => $c['ano']->id + 999]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('porTurma.linhas', [])->has('atribuicoes.data', 0));

        $this->assertSame(0, AtribuicaoDocente::count());
    }

    public function test_delete_de_atribuicao_iniciada_devolve_erro(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $this->actingAsAdmin();
        $iniciada = $this->existente($c, $prof, '2026-01-01');

        $this->delete(route('atribuicoes-docentes.destroy', $iniciada))->assertSessionHasErrors(['atribuicao']);
        $this->assertNotNull(AtribuicaoDocente::find($iniciada->id));
    }
}
