<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocenteHttpTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function como(Perfil $perfil): User
    {
        $u = User::create(['name' => $perfil->value, 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);
        $this->actingAs($u);

        return $u;
    }

    private function corpo(array $sobre = []): array
    {
        return array_merge(['nome_completo' => 'Rui Docente', 'numero_identificacao' => '00112233LA045', 'sexo' => 1], $sobre);
    }

    public function test_rotas_de_atribuicoes_nao_colidem_com_as_do_docente(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->assertSame('/docentes/atribuicoes', route('atribuicoes-docentes.index', [], false));
        $this->assertSame('/docentes', route('docentes.index', [], false));
        $this->get('/docentes/atribuicoes')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Docente/Atribuicoes/Index'));
        $this->get('/docentes')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Docente/Docentes/Index'));
        $this->get('/docentes/abc')->assertNotFound();
    }

    public function test_index_devolve_lista_indicadores_diferidos_e_filtros(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $comAcesso = $this->criarDocente('Ana Com Acesso', comAcesso: true);
        $this->criarDocente('Bruno Sem Acesso');
        $inactivo = $this->criarDocente('Carlos Inactivo');
        $inactivo->update(['estado' => 0]);

        $this->get(route('docentes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Docente/Docentes/Index')
                ->has('docentes.data', 3)
                ->has('docentes.data.0', fn (Assert $d) => $d->hasAll(['id', 'nome', 'bi', 'telefone', 'email', 'estado', 'tem_acesso'])->etc())
                ->where('docentes.data.0.nome', 'Ana Com Acesso')
                ->where('docentes.data.0.tem_acesso', true)
                ->where('docentes.data.1.tem_acesso', false)
                ->has('filtros')
                ->missing('indicadores')
                ->loadDeferredProps(fn (Assert $r) => $r->where('indicadores', ['total' => 3, 'activos' => 2, 'com_acesso' => 1, 'sem_acesso' => 2])));

        $this->get(route('docentes.index', ['pesquisa' => 'bruno']))->assertInertia(fn (Assert $p) => $p->has('docentes.data', 1)->where('filtros.pesquisa', 'bruno'));
        $this->get(route('docentes.index', ['estado' => '0']))->assertInertia(fn (Assert $p) => $p->has('docentes.data', 1)->where('docentes.data.0.nome', 'Carlos Inactivo'));
        $this->get(route('docentes.index', ['acesso' => 'com']))->assertInertia(fn (Assert $p) => $p->has('docentes.data', 1)->where('docentes.data.0.id', $comAcesso->id));
        $this->get(route('docentes.index', ['acesso' => 'sem']))->assertInertia(fn (Assert $p) => $p->has('docentes.data', 2));
    }

    public function test_index_nao_faz_consultas_por_linha(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $this->criarDocente('Docente 0', comAcesso: true);
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('docentes.index'))->assertOk();

            return count(DB::getQueryLog());
        };
        $contar(); // aquece caches (permissões, tenant) para comparar só o que a lista consulta
        $com1 = $contar();

        foreach (range(1, 6) as $i) {
            $this->criarDocente('Docente ' . $i, comAcesso: $i % 2 === 0);
        }

        $this->assertSame($com1, $contar());
    }

    public function test_store_cria_docente_sem_email_nem_conta(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->post(route('docentes.store'), $this->corpo())->assertSessionHasNoErrors()->assertRedirect();

        $docente = Docente::query()->firstOrFail();
        $this->assertSame('Rui Docente', $docente->dadosPessoa->nome_completo);
        $this->assertNull($docente->dadosPessoa->email);
        $this->assertFalse($docente->temAcesso());
    }

    public function test_store_valida_e_rejeita_bi_duplicado(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $this->post(route('docentes.store'), $this->corpo())->assertSessionHasNoErrors();

        $this->post(route('docentes.store'), $this->corpo(['nome_completo' => 'Outro']))->assertSessionHasErrors('numero_identificacao');
        $this->post(route('docentes.store'), [])->assertSessionHasErrors(['nome_completo', 'numero_identificacao']);
        $this->post(route('docentes.store'), $this->corpo(['numero_identificacao' => 'X2', 'email' => 'invalido']))->assertSessionHasErrors('email');
        $this->assertSame(1, Docente::query()->count());
    }

    public function test_update_edita_sem_conflito_com_o_proprio_bi(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Antigo');
        $bi = $docente->dadosPessoa->numero_identificacao;

        $this->put(route('docentes.update', $docente), ['nome_completo' => 'Novo Nome', 'numero_identificacao' => $bi, 'email' => 'n@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Novo Nome', $docente->fresh()->dadosPessoa->nome_completo);

        $outro = $this->criarDocente('Outro');
        $this->put(route('docentes.update', $docente), ['nome_completo' => 'Novo Nome', 'numero_identificacao' => $outro->dadosPessoa->numero_identificacao])
            ->assertSessionHasErrors('numero_identificacao');
    }

    public function test_estado_activa_e_desactiva_e_valida(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente();

        $this->patch(route('docentes.alterar-estado', $docente), ['estado' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, $docente->fresh()->estado);
        $this->patch(route('docentes.alterar-estado', $docente), ['estado' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, $docente->fresh()->estado);
        $this->patch(route('docentes.alterar-estado', $docente), ['estado' => 7])->assertSessionHasErrors('estado');
    }

    public function test_destroy_elimina_e_bloqueia_com_atribuicao_sem_500(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $livre = $this->criarDocente('Livre');
        $this->delete(route('docentes.destroy', $livre))->assertSessionHasNoErrors();
        $this->assertNull(Docente::find($livre->id));

        $c = $this->cenarioDeDocencia();
        $preso = $this->criarDocente('Preso');
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $preso->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-01-10', 'data_fim' => '2026-02-10',
        ])->delete();

        $this->delete(route('docentes.destroy', $preso))->assertSessionHasErrors('docente');
        $this->assertNotNull(Docente::find($preso->id));
    }

    public function test_exportar_csv_com_os_filtros(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $this->criarDocente('Ana Exportada');
        $this->criarDocente('Bruno Outro');

        $resposta = $this->get(route('docentes.exportar', ['formato' => 'csv', 'pesquisa' => 'Ana']))->assertOk();
        $csv = $resposta->streamedContent();

        $this->assertStringContainsString('Ana Exportada', $csv);
        $this->assertStringNotContainsString('Bruno Outro', $csv);
        $this->get(route('docentes.exportar'))->assertSessionHasErrors('formato');
    }

    public function test_funcionario_ve_cria_edita_mas_nao_elimina_nem_exporta(): void
    {
        $this->como(Perfil::FUNCIONARIO);
        $docente = $this->criarDocente();

        $this->get(route('docentes.index'))->assertOk();
        $this->post(route('docentes.store'), $this->corpo())->assertSessionHasNoErrors();
        $this->put(route('docentes.update', $docente), ['nome_completo' => 'X Y', 'numero_identificacao' => $docente->dadosPessoa->numero_identificacao])->assertSessionHasNoErrors();
        $this->patch(route('docentes.alterar-estado', $docente), ['estado' => 0])->assertSessionHasNoErrors();
        $this->delete(route('docentes.destroy', $docente))->assertForbidden();
        $this->get(route('docentes.exportar', ['formato' => 'csv']))->assertForbidden();
    }

    public function test_professor_aluno_e_encarregado_recebem_403_em_tudo(): void
    {
        $docente = $this->criarDocente();

        foreach ([Perfil::PROFESSOR, Perfil::ALUNO, Perfil::ENCARREGADO] as $perfil) {
            $this->como($perfil);
            $this->get(route('docentes.index'))->assertForbidden();
            $this->post(route('docentes.store'), $this->corpo())->assertForbidden();
            $this->put(route('docentes.update', $docente), $this->corpo())->assertForbidden();
            $this->patch(route('docentes.alterar-estado', $docente), ['estado' => 0])->assertForbidden();
            $this->delete(route('docentes.destroy', $docente))->assertForbidden();
            $this->get(route('docentes.exportar', ['formato' => 'csv']))->assertForbidden();
        }

        $this->assertSame(1, Docente::query()->count());
    }

    public function test_isolamento_de_tenant_lista_e_ids_de_outro_tenant_dao_404(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $meu = $this->criarDocente('Meu');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $alheio = $this->noTenant($outro, fn () => $this->criarDocente('Alheio'));

        $this->get(route('docentes.index'))->assertInertia(fn (Assert $p) => $p->has('docentes.data', 1)->where('docentes.data.0.id', $meu->id));
        $this->put(route('docentes.update', $alheio->id), $this->corpo())->assertNotFound();
        $this->patch(route('docentes.alterar-estado', $alheio->id), ['estado' => 0])->assertNotFound();
        $this->delete(route('docentes.destroy', $alheio->id))->assertNotFound();

        $this->assertSame(1, $this->noTenant($outro, fn () => Docente::query()->count()));
    }

    public function test_mesmo_bi_noutro_tenant_e_permitido(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => DadosPessoa::create(['nome_completo' => 'X', 'numero_identificacao' => '00112233LA045', 'tipo_pessoa' => 1]));

        $this->post(route('docentes.store'), $this->corpo())->assertSessionHasNoErrors();
        $this->assertSame(1, Docente::query()->count());
    }

    public function test_bi_repetido_no_mesmo_tenant_e_rejeitado_sem_revelar_outro_tenant(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');
        $this->noTenant($outro, fn () => DadosPessoa::create(['nome_completo' => 'X', 'numero_identificacao' => 'BI-CRUZADO', 'tipo_pessoa' => 1]));

        $this->post(route('docentes.store'), $this->corpo(['numero_identificacao' => 'BI-CRUZADO']))->assertSessionHasNoErrors();
        $this->post(route('docentes.store'), $this->corpo(['nome_completo' => 'Outro', 'numero_identificacao' => 'BI-CRUZADO']))
            ->assertSessionHasErrors('numero_identificacao');
    }

    public function test_update_aceita_bi_existente_noutro_tenant(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Ana');
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');
        $this->noTenant($outro, fn () => DadosPessoa::create(['nome_completo' => 'X', 'numero_identificacao' => 'BI-CRUZADO-2', 'tipo_pessoa' => 1]));

        $this->put(route('docentes.update', $docente), ['nome_completo' => 'Ana', 'numero_identificacao' => 'BI-CRUZADO-2'])
            ->assertSessionHasNoErrors();
    }
}
