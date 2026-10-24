<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;
use RuntimeException;
use Tests\TestCase;

class PropinaGeracaoHttpTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const PREVIA = 'financeiro.propinas.geracao.pre-visualizar';

    private const GERAR = 'financeiro.propinas.geracao.store';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function porTurma(Turma $turma, array $extras = []): array
    {
        return array_merge(['ano_lectivo_id' => $turma->ano_lectivo_id, 'ambito' => 'turma', 'turma_id' => $turma->id], $extras);
    }

    /** Perfil personalizado só com `propina.ver` e `propina.criar` (sem `ajustar`, que o ADMIN_ESCOLA tem desde F2, P1). */
    private function tesoureiroSemAjustar(): User
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Propinas (ver e criar)']);
        $registo = ModuloRegistro::where('nome', Modulo::PROPINA->value)->firstOrFail();

        foreach (Acao::query()->whereIn('nome', ['ver', 'criar'])->get() as $acao) {
            RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $registo->id, 'acao_id' => $acao->id]);
        }

        $user = User::create(['name' => 'Tesouraria', 'email' => 'tesouraria@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_pre_visualizar_devolve_o_resultado_e_nao_grava(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->actingAs($this->adminEscola())
            ->postJson(route(self::PREVIA), $this->porTurma($turma))
            ->assertOk()
            ->assertJsonPath('resultado.previsualizacao', true)
            ->assertJsonPath('resultado.totais.criadas', 10)
            ->assertJsonPath('resultado.matriculas.0.criadas.0.periodo', 'Set/2026')
            ->assertJsonPath('resultado.detalhe_truncado', false);

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_gerar_grava_e_devolve_o_resumo_em_flash(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->actingAs($this->adminEscola());

        $this->from(route('financeiro.propinas.index'))
            ->post(route(self::GERAR), $this->porTurma($turma))
            ->assertRedirect(route('financeiro.propinas.index'))
            ->assertSessionHas('success', '10 propina(s) gerada(s), 0 período(s) ignorado(s), 0 conflito(s), 0 erro(s).')
            ->assertSessionMissing('warning')
            ->assertSessionHas('geracao_propinas', fn (array $r) => $r['previsualizacao'] === false && $r['totais']['criadas'] === 10);

        $this->assertSame(10, Propina::query()->count());

        $this->post(route(self::GERAR), $this->porTurma($turma))
            ->assertSessionHas('geracao_propinas', fn (array $r) => $r['totais']['criadas'] === 0 && $r['totais']['ignoradas'] === 10);
        $this->assertSame(10, Propina::query()->count());
    }

    public function test_ambitos_matricula_nivel_e_plano(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'matricula', 'matricula_numero' => $matricula->numero_registo_matricula])
            ->assertOk()->assertJsonPath('resultado.totais.matriculas', 1);
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'nivel', 'nivel_academico_id' => $turma->nivel_academico_id])
            ->assertOk()->assertJsonPath('resultado.totais.criadas', 10);
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'plano', 'plano_propina_id' => $plano->id])
            ->assertOk()->assertJsonPath('resultado.totais.criadas', 10);
    }

    public function test_sem_propina_criar_da_403_e_nada_e_gravado(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->actingAs($this->professor());

        $this->postJson(route(self::PREVIA), $this->porTurma($turma))->assertForbidden();
        $this->post(route(self::GERAR), $this->porTurma($turma))->assertForbidden();

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_periodos_anteriores_exigem_propina_ajustar(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $tardia = $this->matriculaActiva($turma, '2026-11-15');
        $pedido = [
            'ano_lectivo_id' => $turma->ano_lectivo_id,
            'ambito' => 'matricula',
            'matricula_numero' => $tardia->numero_registo_matricula,
            'incluir_periodos_anteriores' => true,
            'motivo_geracao' => 'Regularização de Setembro e Outubro.',
        ];

        $this->actingAs($this->tesoureiroSemAjustar())->postJson(route(self::PREVIA), $pedido)->assertForbidden();
        $this->post(route(self::GERAR), $pedido)->assertForbidden();
        $this->assertSame(0, Propina::query()->count());

        // P1: o ADMIN_ESCOLA tem `propina.ajustar` desde F2.
        $this->actingAs($this->adminEscola())
            ->postJson(route(self::PREVIA), $pedido)
            ->assertOk()
            ->assertJsonPath('resultado.totais.criadas', 10)
            ->assertJsonPath('resultado.matriculas.0.criadas.0.origem_geracao', 4)
            ->assertJsonPath('resultado.matriculas.0.criadas.0.motivo_geracao', 'Regularização de Setembro e Outubro.');
        $this->assertSame(0, Propina::query()->count(), 'A pré-visualização não grava.');

        $this->post(route(self::GERAR), $pedido)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(
            ['2026-09-01' => OrigemGeracaoPropina::RETROACTIVA, '2026-10-01' => OrigemGeracaoPropina::RETROACTIVA, '2026-11-01' => OrigemGeracaoPropina::MANUAL],
            Propina::query()->where('matricula_id', $tardia->id)->whereIn('periodo_inicio', ['2026-09-01', '2026-10-01', '2026-11-01'])
                ->orderBy('periodo_inicio')->get()->mapWithKeys(fn (Propina $p) => [$p->periodo_inicio->toDateString() => $p->origem_geracao])->all(),
        );
        $this->assertSame(
            ['Regularização de Setembro e Outubro.'],
            Propina::query()->where('origem_geracao', OrigemGeracaoPropina::RETROACTIVA->value)->pluck('motivo_geracao')->unique()->values()->all(),
        );
        $this->assertSame(8, Propina::query()->where('origem_geracao', OrigemGeracaoPropina::MANUAL->value)->whereNull('motivo_geracao')->count());
    }

    public function test_com_erros_o_flash_e_de_aviso_e_inclui_todas_as_contagens(): void
    {
        ['turma' => $turma, 'matricula' => $primeira] = $this->cenarioPropinas();
        $this->matriculaActiva($turma);
        $falhou = false;
        Propina::creating(function (Propina $propina) use ($primeira, &$falhou) {
            if (! $falhou && $propina->matricula_id === $primeira->id) {
                $falhou = true;

                throw new RuntimeException('Falha simulada.');
            }
        });
        $this->actingAs($this->adminEscola());

        $this->from(route('financeiro.propinas.index'))
            ->post(route(self::GERAR), $this->porTurma($turma))
            ->assertRedirect(route('financeiro.propinas.index'))
            ->assertSessionHas('warning', '10 propina(s) gerada(s), 0 período(s) ignorado(s), 0 conflito(s), 1 erro(s). Veja o detalhe.')
            ->assertSessionMissing('success')
            ->assertSessionHas('geracao_propinas', fn (array $r) => $r['totais']['erros'] === 1);
    }

    public function test_acima_de_500_matriculas_pede_para_restringir_o_ambito_e_nada_e_calculado_nem_gravado(): void
    {
        // P5: a geração manual trata no máximo GerarPropinasService::LIMITE_MATRICULAS_POR_PEDIDO matrículas por pedido.
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();

        for ($i = 1; $i < GerarPropinasService::LIMITE_MATRICULAS_POR_PEDIDO; $i++) {
            $this->matriculaActiva($turma);
        }

        $this->actingAs($this->adminEscola());
        $this->postJson(route(self::PREVIA), $this->porTurma($turma))
            ->assertOk()
            ->assertJsonPath('resultado.totais.matriculas', 500);

        $this->matriculaActiva($turma);
        $mensagem = 'O âmbito escolhido tem 501 matrículas; a geração manual trata no máximo 500 por pedido. Seleccione matrículas individuais.';

        $this->postJson(route(self::PREVIA), $this->porTurma($turma))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ambito' => $mensagem]);
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'nivel', 'nivel_academico_id' => $turma->nivel_academico_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ambito' => 'O âmbito escolhido tem 501 matrículas; a geração manual trata no máximo 500 por pedido. Restrinja por turma.']);
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'plano', 'plano_propina_id' => $plano->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ambito' => 'O âmbito escolhido tem 501 matrículas; a geração manual trata no máximo 500 por pedido. Restrinja por turma ou por nível académico.']);
        $this->from(route('financeiro.propinas.index'))
            ->post(route(self::GERAR), $this->porTurma($turma))
            ->assertRedirect(route('financeiro.propinas.index'))
            ->assertSessionHasErrors(['ambito' => $mensagem])
            ->assertSessionMissing('geracao_propinas');

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_a_origem_nunca_vem_do_cliente_e_matricula_esta_reservada_a_f3(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->actingAs($this->adminEscola());

        $this->post(route(self::GERAR), $this->porTurma($turma, ['origem_geracao' => OrigemGeracaoPropina::MATRICULA->value, 'origem' => 'matricula']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Propina::query()->where('origem_geracao', OrigemGeracaoPropina::MATRICULA->value)->count());
        $this->assertSame(10, Propina::query()->where('origem_geracao', OrigemGeracaoPropina::MANUAL->value)->count());
    }

    public function test_motivo_obrigatorio_com_periodos_anteriores(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->actingAs($this->adminEscola())
            ->postJson(route(self::PREVIA), $this->porTurma($turma, ['incluir_periodos_anteriores' => true, 'motivo_geracao' => '   ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo_geracao');
    }

    public function test_ids_de_outro_tenant_de_outro_ano_ou_inactivos_dao_422(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $turmaDoOutro = $this->noTenant($outro, fn () => $this->cenarioPropinas()['turma']);
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $planoDoOutroAno = $this->plano($outroAno, 'Futuro');
        $inactivo = $this->plano($ano, 'Inactivo', ['estado' => Estado::INATIVO->value]);
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'turma', 'turma_id' => $turmaDoOutro->id])
            ->assertStatus(422)->assertJsonValidationErrors('turma_id');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $turmaDoOutro->ano_lectivo_id, 'ambito' => 'turma', 'turma_id' => $turma->id])
            ->assertStatus(422)->assertJsonValidationErrors(['ano_lectivo_id', 'turma_id']);
        $this->postJson(route(self::PREVIA), $this->porTurma($turma, ['plano_propina_id' => $planoDoOutroAno->id]))
            ->assertStatus(422)->assertJsonValidationErrors('plano_propina_id');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'plano', 'plano_propina_id' => $inactivo->id])
            ->assertStatus(422)->assertJsonValidationErrors('plano_propina_id');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'matricula', 'matricula_numero' => 'NAO-EXISTE'])
            ->assertStatus(422)->assertJsonValidationErrors('matricula_numero');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'todas'])
            ->assertStatus(422)->assertJsonValidationErrors('ambito');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'plano'])
            ->assertStatus(422)->assertJsonValidationErrors('plano_propina_id');
    }
}
