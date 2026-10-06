<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Models\Horario;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\TurmaSala;
use Modules\Turma\Models\Turno;
use Modules\Turma\Models\TurnoHorario;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class TurmaTenancyTest extends TestCase
{
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

    private function ano(string $nome = '2026'): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'nome' => $nome,
            'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::PLANEADO,
        ]);
    }

    private function nivel(string $codigo = 'N1', string $nome = 'Nivel A', int $ordem = 1): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome,
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => $ordem,
        ]);
    }

    private function turno(string $nome = 'Manha'): Turno
    {
        return Turno::create(['estabelecimento_id' => Estabelecimento::current()->id, 'nome' => $nome]);
    }

    private function turma(AnoLectivo $ano, NivelAcademico $nivel, string $codigo = 'T1', string $nome = 'Turma A', ?Turno $turno = null): Turma
    {
        return Turma::create([
            'ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id,
            'codigo' => $codigo, 'nome' => $nome, 'turno_id' => $turno?->id,
        ]);
    }

    /** Cria no tenant B: ano, nível, turno, curso, sala, horário e turma. */
    private function dadosDeB(string $nome = 'Turma B'): array
    {
        return $this->noTenant($this->outro, function () use ($nome) {
            $estabelecimentoId = Estabelecimento::current()->id;
            $ano = $this->ano('2026');
            $nivel = $this->nivel('N1', 'Nivel B');
            $turno = $this->turno('Manha B');
            $curso = Curso::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => 'C1', 'nome' => 'Curso B']);
            $sala = Sala::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => 'S1', 'nome' => 'Sala B', 'tipo' => TipoSala::SALA_AULA->value]);
            $horario = Horario::create(['nome' => 'H B', 'hora_inicio' => '08:00', 'hora_fim' => '09:00']);

            return [
                'ano' => $ano, 'nivel' => $nivel, 'turno' => $turno, 'curso' => $curso, 'sala' => $sala, 'horario' => $horario,
                'turma' => $this->turma($ano, $nivel, 'T1', $nome, $turno),
            ];
        });
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $nivelA = $this->nivel('NA', 'Nivel So Em A');
        $this->turno('Turno So Em A');
        $this->turma($this->ano(), $nivelA, 'TA', 'Turma So Em A');
        $this->dadosDeB('Turma So Em B');

        foreach (['/turmas?ano_lectivo_id=', '/niveis-academicos', '/turnos'] as $caminho) {
            $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, $caminho));
            $resposta->assertOk();
            $conteudo = $resposta->getContent();
            $this->assertStringNotContainsString('So Em B', $conteudo, $caminho);
            $this->assertStringNotContainsString('Nivel B', $conteudo, $caminho);
            $this->assertStringNotContainsString('Manha B', $conteudo, $caminho);
        }

        $this->assertStringContainsString('Turma So Em A', $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/turmas?ano_lectivo_id='))->getContent());
        $this->assertStringContainsString('Nivel So Em A', $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/niveis-academicos'))->getContent());
        $this->assertStringContainsString('Turno So Em A', $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/turnos'))->getContent());
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $b = $this->dadosDeB();
        $turmaA = $this->turma($this->ano(), $this->nivel('NA', 'Nivel A'), 'TA', 'Turma A');
        $nivelA = $turmaA->nivelAcademico;
        $turnoA = $this->turno('Turno A');

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/turmas/{$turmaA->id}"))->assertOk();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/niveis-academicos/{$nivelA->id}"))->assertOk();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/turnos/{$turnoA->id}"))->assertOk();

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/turmas/{$b['turma']->id}"))->assertNotFound();
        $this->actingAs($admin)->put($this->urlDoTenant($this->tenant, "/turmas/{$b['turma']->id}"), ['nome' => 'Invadida'])->assertNotFound();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/niveis-academicos/{$b['nivel']->id}"))->assertNotFound();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/turnos/{$b['turno']->id}"))->assertNotFound();
        $this->actingAs($admin)->patch($this->urlDoTenant($this->tenant, "/turnos/{$b['turno']->id}/estado"), ['estado' => 0])->assertNotFound();

        $this->assertDatabaseHas('turmas', ['id' => $b['turma']->id, 'nome' => 'Turma B']);
        $this->assertDatabaseHas('turnos', ['id' => $b['turno']->id, 'estado' => 1]);
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $b = $this->dadosDeB();
        $turmaA = $this->turma($this->ano(), $this->nivel(), 'TA', 'Turma A');
        $pares = [
            'turmas' => [$turmaA->id, $b['turma']->id],
            'niveis_academicos' => [$turmaA->nivel_academico_id, $b['nivel']->id],
            'turnos' => [$this->turno()->id, $b['turno']->id],
        ];

        foreach ($pares as $tabela => [$idDeA, $idDeB]) {
            $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => "exists:{$tabela},id"])->passes(), $tabela);
            $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => "exists:{$tabela},id"])->fails(), $tabela);
        }
    }

    public function test_unique_e_por_tenant(): void
    {
        $ano = $this->ano();
        $nivel = $this->nivel('N1', 'Nivel A');
        $this->turno('Manha');
        $this->turma($ano, $nivel, 'T1', 'Turma A');

        // Os mesmos códigos/nomes em B são aceites.
        $this->noTenant($this->outro, function () {
            $this->turma($this->ano(), $this->nivel('N1', 'Nivel A'), 'T1', 'Turma A');
            $this->turno('Manha');
        });

        $this->assertSame(1, Turma::where('codigo', 'T1')->count());
        $this->assertSame(1, NivelAcademico::where('codigo', 'N1')->count());
        $this->assertSame(1, Turno::where('nome', 'Manha')->count());

        $duplicados = [
            fn () => $this->turma($ano, $nivel, 'T1', 'Outra'),
            fn () => $this->nivel('N1', 'Outro Nome', 2),
            fn () => $this->nivel('N2', 'Nivel A', 2),
            fn () => $this->turno('Manha'),
        ];
        foreach ($duplicados as $duplicado) {
            try {
                $duplicado();
                $this->fail('O duplicado dentro de A devia ser recusado pela BD.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $estabelecimentoId = Estabelecimento::current()->id;
        $regra = fn (string $tabela, string $campo, string $valor) => Validator::make([$campo => $valor], [
            $campo => ['required', Rule::unique($tabela, $campo)
                ->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))],
        ]);
        $this->assertTrue($regra('niveis_academicos', 'codigo', 'N1')->fails());
        $this->assertTrue($regra('niveis_academicos', 'codigo', 'NOVO')->passes());
        $this->assertTrue($regra('turnos', 'nome', 'Manha')->fails());
        $this->assertTrue($regra('turnos', 'nome', 'Tarde')->passes());

        // Variante sem o where de estabelecimento: o VerificadorPresencaTenant filtra por tenant por si só.
        $this->noTenant($this->outro, function () {
            $this->nivel('SOB', 'So em B');
            $this->turno('So em B');
        });
        $semWhere = fn (string $tabela, string $campo, string $valor) => Validator::make([$campo => $valor], [
            $campo => ['required', 'unique:' . $tabela . ',' . $campo],
        ]);
        $this->assertTrue($semWhere('niveis_academicos', 'codigo', 'N1')->fails());
        $this->assertTrue($semWhere('niveis_academicos', 'codigo', 'SOB')->passes(), 'Um código que só existe em B não pode bloquear A.');
        $this->assertTrue($semWhere('turnos', 'nome', 'Manha')->fails());
        $this->assertTrue($semWhere('turnos', 'nome', 'So em B')->passes(), 'Um nome que só existe em B não pode bloquear A.');
    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $turma = $this->turma($this->ano(), $this->nivel(), 'T1', 'Turma A', $this->turno());
        $sala = Sala::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'S1', 'nome' => 'S', 'tipo' => TipoSala::SALA_AULA->value]);
        $turmaSala = TurmaSala::create(['turma_id' => $turma->id, 'sala_id' => $sala->id, 'inicio' => '2026-02-01']);
        $horario = Horario::create(['nome' => 'H', 'hora_inicio' => '08:00', 'hora_fim' => '09:00']);
        $turnoHorario = TurnoHorario::create(['turno_id' => $turma->turno_id, 'horario_id' => $horario->id, 'ordem' => 1]);

        foreach ([$turma, $turma->nivelAcademico, $turma->turno, $turmaSala, $turnoHorario] as $modelo) {
            $this->assertSame($this->tenant->id, (int) $modelo->fresh()->tenant_id, $modelo::class);

            $recarregado = $modelo->fresh();
            $recarregado->tenant_id = $this->outro->id;
            try {
                $recarregado->save();
                $this->fail('Alterar o tenant devia lançar excepção em ' . $modelo::class);
            } catch (AlteracaoDeTenantProibida) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        $turma = $this->turma($this->ano(), $this->nivel(), 'T1', 'Turma A', $this->turno());

        app(TenantContext::class)->limpar();

        $operacoes = [
            fn () => Turma::query()->get(),
            fn () => NivelAcademico::query()->get(),
            fn () => Turno::query()->get(),
            fn () => TurmaSala::query()->get(),
            fn () => TurnoHorario::query()->get(),
            fn () => Turma::create(['codigo' => 'X', 'nome' => 'X']),
            fn () => $turma->update(['nome' => 'Y']),
            fn () => TurmaSala::create(['turma_id' => 1, 'sala_id' => 1, 'inicio' => '2026-02-01']),
            fn () => TurnoHorario::create(['turno_id' => 1, 'horario_id' => 1, 'ordem' => 1]),
        ];

        foreach ($operacoes as $operacao) {
            try {
                $operacao();
                $this->fail('Sem contexto devia lançar TenantNaoResolvido.');
            } catch (TenantNaoResolvido) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_bd_rejeita_estabelecimento_de_outro_tenant(): void
    {
        $estabelecimentoDeB = $this->noTenant($this->outro, fn () => Estabelecimento::current()->id);
        $agora = now();

        $inserts = [
            'turnos' => ['nome' => 'Cruzado'],
            'niveis_academicos' => ['codigo' => 'CRZ', 'nome' => 'Cruzado', 'ordem' => 1, 'etapa_ensino' => 1, 'etapa_ensino_descricao' => 'x'],
        ];

        foreach ($inserts as $tabela => $colunas) {
            try {
                DB::table($tabela)->insert(array_merge($colunas, [
                    'tenant_id' => $this->tenant->id,
                    'estabelecimento_id' => $estabelecimentoDeB,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]));
                $this->fail("A BD devia rejeitar o estabelecimento de outro tenant em {$tabela}.");
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_a_relacao_nao_atravessa_tenants(): void
    {
        $b = $this->dadosDeB();
        $turma = $this->turma($this->ano(), $this->nivel());

        // Um pai de A, apontado à força para o ID de um pai de B, não o resolve.
        $turma->forceFill([
            'ano_lectivo_id' => $b['ano']->id, 'nivel_academico_id' => $b['nivel']->id, 'turno_id' => $b['turno']->id,
        ]);
        $this->assertNull($turma->anoLectivo);
        $this->assertNull($turma->nivelAcademico);
        $this->assertNull($turma->turno);

        $this->assertCount(0, $this->turno()->turmas);
        $this->assertCount(0, $this->noTenant($this->outro, fn () => $b['nivel']->fresh()->turmas()->where('codigo', 'X')->get()));
    }

    public function test_turma_nao_referencia_ano_lectivo_nivel_ou_curso_de_outro_tenant(): void
    {
        $admin = $this->administrador();
        $b = $this->dadosDeB();
        $anoA = $this->ano();
        $nivelA = $this->nivel();
        $cursoA = Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'CA', 'nome' => 'Curso A']);

        $valido = ['ano_lectivo_id' => $anoA->id, 'nivel_academico_id' => $nivelA->id, 'curso_id' => $cursoA->id, 'codigo' => 'NOVA', 'nome' => 'Nova'];

        foreach ([
            'ano_lectivo_id' => $b['ano']->id,
            'nivel_academico_id' => $b['nivel']->id,
            'curso_id' => $b['curso']->id,
            'turno_id' => $b['turno']->id,
        ] as $campo => $idDeB) {
            $this->actingAs($admin)
                ->post($this->urlDoTenant($this->tenant, '/turmas'), array_merge($valido, [$campo => $idDeB]))
                ->assertSessionHasErrors($campo);
            $this->assertSame(0, Turma::where('codigo', 'NOVA')->count(), $campo);
        }

        // Controlo positivo: com os ids de A a criação é aceite.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/turmas'), $valido)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Turma::where('codigo', 'NOVA')->count());

        // Actualizar uma turma de A com um nível de B também falha.
        $turmaA = Turma::where('codigo', 'NOVA')->firstOrFail();
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/turmas/{$turmaA->id}"), array_merge($valido, ['nivel_academico_id' => $b['nivel']->id]))
            ->assertSessionHasErrors('nivel_academico_id');
        $this->assertSame($nivelA->id, $turmaA->fresh()->nivel_academico_id);

        // E associar uma sala de B a uma turma de A.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/turmas/{$turmaA->id}/salas"), ['sala_id' => $b['sala']->id, 'inicio' => '2026-02-01'])
            ->assertSessionHasErrors('sala_id');
        $this->assertSame(0, TurmaSala::count());
    }

    public function test_turma_salas_e_turno_horarios_nao_atravessam_tenants(): void
    {
        $admin = $this->administrador();
        $b = $this->dadosDeB();
        $turmaA = $this->turma($this->ano(), $this->nivel());
        $turnoA = $this->turno();
        $salaA = Sala::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'SA', 'nome' => 'Sala A', 'tipo' => TipoSala::SALA_AULA->value]);
        $horarioA = Horario::create(['nome' => 'H A', 'hora_inicio' => '10:00', 'hora_fim' => '11:00']);

        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/turmas/{$turmaA->id}/salas"), ['sala_id' => $salaA->id, 'inicio' => '2026-02-01'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/turnos/{$turnoA->id}/horarios"), ['horario_id' => $horarioA->id, 'ordem' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, (int) TurmaSala::firstOrFail()->tenant_id);
        $this->assertSame($this->tenant->id, (int) TurnoHorario::firstOrFail()->tenant_id);

        // Horário de B num turno de A: rejeitado.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/turnos/{$turnoA->id}/horarios"), ['horario_id' => $b['horario']->id, 'ordem' => 2])
            ->assertSessionHasErrors('horario_id');
        $this->assertSame(1, TurnoHorario::count());

        // Pivots de B invisíveis em A, e vice-versa.
        $this->noTenant($this->outro, function () use ($b) {
            TurmaSala::create(['turma_id' => $b['turma']->id, 'sala_id' => $b['sala']->id, 'inicio' => '2026-02-01']);
            TurnoHorario::create(['turno_id' => $b['turno']->id, 'horario_id' => $b['horario']->id, 'ordem' => 1]);
        });
        $this->assertSame(1, TurmaSala::count());
        $this->assertSame(1, TurnoHorario::count());
        $this->assertSame(1, $this->noTenant($this->outro, fn () => TurmaSala::count()));

        // A relação não atravessa: o pivot de A, apontado a um pai de B, não o resolve.
        $turmaSala = TurmaSala::firstOrFail();
        $turmaSala->forceFill(['turma_id' => $b['turma']->id, 'sala_id' => $b['sala']->id]);
        $this->assertNull($turmaSala->turma);
        $this->assertNull($turmaSala->sala);

        $turnoHorario = TurnoHorario::firstOrFail();
        $turnoHorario->forceFill(['turno_id' => $b['turno']->id, 'horario_id' => $b['horario']->id]);
        $this->assertNull($turnoHorario->turno);
        $this->assertNull($turnoHorario->horario);
    }

    public function test_contagens_de_turma_so_contam_o_tenant(): void
    {
        $this->turma($this->ano(), $this->nivel(), 'T1', 'Turma A');
        $this->dadosDeB();
        $this->noTenant($this->outro, fn () => $this->turma($this->ano('2027'), $this->nivel('N9', 'Nivel Extra B', 9), 'T9', 'Extra B'));

        $this->assertSame(1, Turma::count());
        $this->assertSame(1, NivelAcademico::count());
        $this->assertSame(0, Turno::count());
        $this->assertSame(2, $this->noTenant($this->outro, fn () => Turma::count()));
    }
}
