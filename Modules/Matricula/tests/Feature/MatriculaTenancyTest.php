<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Matricula\Services\GeradorNumeroRegistoMatriculaService;
use Modules\Matricula\Services\MatriculaConsultaService;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class MatriculaTenancyTest extends TestCase
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

    /**
     * Cadeia completa no tenant do contexto: ano, nível, turma, aluno, plano
     * confirmado com uma disciplina, matrícula (+ histórico e inscrição).
     * Corre-se dentro de `noTenant()` para criar os dados de B.
     */
    private function dados(string $nome, string $numeroRegisto, string $codigo = 'X'): array
    {
        $estabelecimentoId = Estabelecimento::current()->id;

        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimentoId, 'nome' => '2026', 'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma ' . $nome]);
        $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => 'BI-' . $codigo, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimentoId, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-' . $codigo]);

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoId, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano']);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => 'MAT1', 'nome' => 'Matemática']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $ano->id,
            'numero_registo_matricula' => $numeroRegisto, 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);
        $historico = MatriculaHistorico::create(['matricula_id' => $matricula->id, 'estado_novo' => EstadoMatriculaEnum::PENDENTE->value]);
        $inscricao = InscricaoDisciplina::create([
            'matricula_id' => $matricula->id, 'plano_curricular_disciplina_id' => $planoDisciplina->id,
            'data_inscricao' => '2026-02-01', 'estado' => 1,
        ]);

        return compact('ano', 'nivel', 'turma', 'aluno', 'plano', 'planoDisciplina', 'matricula', 'historico', 'inscricao');
    }

    private function dadosDeA(string $nome = 'Aluno A', string $numero = '2026-0001'): array
    {
        return $this->dados($nome, $numero, 'A');
    }

    private function dadosDeB(string $nome = 'Aluno B', string $numero = '2026-0001'): array
    {
        return $this->noTenant($this->outro, fn () => $this->dados($nome, $numero, 'B'));
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->dadosDeA('Aluno So Em A', '2026-0001');
        $this->dadosDeB('Aluno So Em B', '2026-0002');

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/matriculas'));
        $resposta->assertOk();
        $conteudo = $resposta->getContent();

        $this->assertStringContainsString('Aluno So Em A', $conteudo);
        $this->assertStringNotContainsString('Aluno So Em B', $conteudo);

        // Contagem do Service: a de A não inclui B.
        $this->assertSame(1, app(MatriculaConsultaService::class)->listarTodas()->total());
        $this->assertSame(1, Matricula::count());
        $this->assertSame(1, InscricaoDisciplina::count());
        $this->assertSame(1, MatriculaHistorico::count());
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();

        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/alunos/{$a['aluno']->id}/matriculas/{$a['matricula']->id}/historico"))
            ->assertOk();

        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/alunos/{$a['aluno']->id}/matriculas/{$b['matricula']->id}/historico"))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/alunos/{$b['aluno']->id}/matriculas/{$b['matricula']->id}/historico"))
            ->assertNotFound();
        $this->actingAs($admin)
            ->patch($this->urlDoTenant($this->tenant, "/alunos/{$a['aluno']->id}/matriculas/{$b['matricula']->id}/estado"), ['estado' => EstadoMatriculaEnum::ACTIVA->value])
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete($this->urlDoTenant($this->tenant, "/alunos/{$a['aluno']->id}/matriculas/{$b['matricula']->id}/disciplinas/{$b['inscricao']->id}"))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/turmas/{$b['turma']->id}/plano-curricular"))
            ->assertNotFound();

        $this->assertDatabaseHas('matriculas', ['id' => $b['matricula']->id, 'estado' => EstadoMatriculaEnum::PENDENTE->value]);
        $this->assertDatabaseHas('inscricoes_disciplinas', ['id' => $b['inscricao']->id, 'deleted_at' => null]);
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();
        $pares = [
            'matriculas' => [$a['matricula']->id, $b['matricula']->id],
            'matricula_historicos' => [$a['historico']->id, $b['historico']->id],
            'inscricoes_disciplinas' => [$a['inscricao']->id, $b['inscricao']->id],
        ];

        foreach ($pares as $tabela => [$idDeA, $idDeB]) {
            $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => "exists:{$tabela},id"])->passes(), $tabela);
            $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => "exists:{$tabela},id"])->fails(), $tabela);
        }
    }

    public function test_unique_e_por_tenant(): void
    {
        $this->dadosDeA('Aluno A', '2026-0001');

        // O mesmo número de registo em B é aceite (inserido pelo Model, com o tenant explícito).
        $b = $this->dadosDeB('Aluno B', '2026-0001');
        $this->assertSame(1, Matricula::where('numero_registo_matricula', '2026-0001')->count());

        // Duplicado dentro de A: recusado pela BD.
        $a = Matricula::firstOrFail();
        try {
            Matricula::create([
                'aluno_id' => $a->aluno_id, 'turma_id' => $a->turma_id, 'ano_lectivo_id' => $a->ano_lectivo_id,
                'numero_registo_matricula' => '2026-0001', 'data_matricula' => '2026-02-01',
                'estado' => EstadoMatriculaEnum::PENDENTE->value,
            ]);
            $this->fail('O duplicado dentro de A devia ser recusado pela BD.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $comWhere = fn (string $numero) => Validator::make(['n' => $numero], [
            'n' => ['required', Rule::unique('matriculas', 'numero_registo_matricula')->where(fn ($q) => $q->whereNull('deleted_at'))],
        ]);
        $semWhere = fn (string $numero) => Validator::make(['n' => $numero], ['n' => 'required|unique:matriculas,numero_registo_matricula']);

        $this->assertTrue($comWhere('2026-0001')->fails());
        $this->assertTrue($comWhere('2026-9999')->passes());
        $this->assertTrue($semWhere('2026-0001')->fails());
        $this->assertTrue($semWhere('2026-9999')->passes());

        // Um número que só existe em B é livre em A.
        $this->noTenant($this->outro, fn () => Matricula::create([
            'aluno_id' => $b['aluno']->id, 'turma_id' => $b['turma']->id, 'ano_lectivo_id' => $b['ano']->id,
            'numero_registo_matricula' => '2026-7777', 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]));
        $this->assertTrue($semWhere('2026-7777')->passes());
        $this->assertTrue($comWhere('2026-7777')->passes());
    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $a = $this->dadosDeA();

        foreach ([$a['matricula'], $a['historico'], $a['inscricao']] as $modelo) {
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
        $a = $this->dadosDeA();

        app(TenantContext::class)->limpar();

        $operacoes = [
            fn () => Matricula::query()->get(),
            fn () => MatriculaHistorico::query()->get(),
            fn () => InscricaoDisciplina::query()->get(),
            fn () => $a['matricula']->update(['observacoes' => 'x']),
            fn () => Matricula::create(['aluno_id' => 1, 'turma_id' => 1, 'ano_lectivo_id' => 1, 'numero_registo_matricula' => 'X', 'data_matricula' => '2026-01-01', 'estado' => 1]),
            fn () => MatriculaHistorico::create(['matricula_id' => 1, 'estado_novo' => 1]),
            fn () => InscricaoDisciplina::create(['matricula_id' => 1, 'plano_curricular_disciplina_id' => 1, 'data_inscricao' => '2026-01-01', 'estado' => 1]),
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

    public function test_a_relacao_nao_atravessa_tenants(): void
    {
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();

        $matricula = $a['matricula'];
        $matricula->forceFill(['aluno_id' => $b['aluno']->id, 'turma_id' => $b['turma']->id, 'ano_lectivo_id' => $b['ano']->id]);
        $this->assertNull($matricula->aluno);
        $this->assertNull($matricula->turma);
        $this->assertNull($matricula->anoLectivo);

        $inscricao = $a['inscricao'];
        $inscricao->forceFill(['matricula_id' => $b['matricula']->id, 'plano_curricular_disciplina_id' => $b['planoDisciplina']->id]);
        $this->assertNull($inscricao->matricula);
        $this->assertNull($inscricao->planoCurricularDisciplina);

        $historico = $a['historico'];
        $historico->forceFill(['matricula_id' => $b['matricula']->id]);
        $this->assertNull($historico->matricula);

        // Relações inversas: a matrícula de A só vê o seu histórico e inscrições.
        $this->assertCount(1, $a['matricula']->fresh()->historico);
        $this->assertCount(1, $a['matricula']->fresh()->inscricoesDisciplinas);
    }

    public function test_matricula_nao_referencia_aluno_turma_ano_ou_disciplina_do_plano_de_outro_tenant(): void
    {
        $admin = $this->administrador();
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();
        $antes = Matricula::count();

        // turma_id / ano_lectivo_id de B: erro de validação, nada gravado.
        $url = $this->urlDoTenant($this->tenant, "/alunos/{$a['aluno']->id}/matriculas");
        $this->actingAs($admin)
            ->post($url, ['turma_id' => $b['turma']->id, 'ano_lectivo_id' => $a['ano']->id])
            ->assertSessionHasErrors('turma_id');
        $this->actingAs($admin)
            ->post($url, ['turma_id' => $a['turma']->id, 'ano_lectivo_id' => $b['ano']->id])
            ->assertSessionHasErrors('ano_lectivo_id');
        $this->actingAs($admin)
            ->put("{$url}/{$a['matricula']->id}", ['turma_id' => $b['turma']->id, 'ano_lectivo_id' => $a['ano']->id, 'data_matricula' => '2026-02-01'])
            ->assertSessionHasErrors('turma_id');
        $this->actingAs($admin)
            ->put("{$url}/{$a['matricula']->id}", ['turma_id' => $a['turma']->id, 'ano_lectivo_id' => $b['ano']->id, 'data_matricula' => '2026-02-01'])
            ->assertSessionHasErrors('ano_lectivo_id');
        $this->actingAs($admin)
            ->post("{$url}/{$a['matricula']->id}/renovar", ['turma_id' => $b['turma']->id])
            ->assertSessionHasErrors('turma_id');
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/matriculas/renovar-em-massa'), ['matricula_ids' => [$b['matricula']->id], 'ano_lectivo_id' => $a['ano']->id, 'turma_id' => $a['turma']->id])
            ->assertSessionHasErrors();
        $this->assertSame($antes, Matricula::count());
        $this->assertSame($a['turma']->id, $a['matricula']->fresh()->turma_id);

        // plano_curricular_disciplina_id de B.
        $this->actingAs($admin)
            ->post("{$url}/{$a['matricula']->id}/disciplinas", ['plano_curricular_disciplina_id' => $b['planoDisciplina']->id])
            ->assertSessionHasErrors('plano_curricular_disciplina_id');
        $this->assertSame(1, InscricaoDisciplina::count());

        // aluno_id de B (vem na rota): 404 e nada gravado.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, "/alunos/{$b['aluno']->id}/matriculas"), ['turma_id' => $a['turma']->id, 'ano_lectivo_id' => $a['ano']->id])
            ->assertNotFound();
        $this->assertSame($antes, Matricula::count());

        // exists rejeita cada id de B e aceita o de A.
        foreach ([
            'turmas' => [$a['turma']->id, $b['turma']->id],
            'ano_lectivos' => [$a['ano']->id, $b['ano']->id],
            'plano_curricular_disciplinas' => [$a['planoDisciplina']->id, $b['planoDisciplina']->id],
            'alunos' => [$a['aluno']->id, $b['aluno']->id],
        ] as $tabela => [$idDeA, $idDeB]) {
            $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => "exists:{$tabela},id"])->passes(), $tabela);
            $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => "exists:{$tabela},id"])->fails(), $tabela);
        }
    }

    public function test_historico_e_inscricoes_gravam_o_tenant_do_contexto(): void
    {
        $this->dadosDeB('Aluno B', '2026-0001');

        $nome = 'Aluno A';
        $estabelecimentoId = Estabelecimento::current()->id;
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimentoId, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma']);
        $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => 'BI-AA', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimentoId, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-AA']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoId, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano']);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => 'MAT1', 'nome' => 'Matemática']);
        PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO($turma->id, $ano->id, '2026-02-01', null, null));

        $this->assertSame($this->tenant->id, (int) $matricula->fresh()->tenant_id);

        $historicos = MatriculaHistorico::where('matricula_id', $matricula->id)->get();
        $this->assertCount(1, $historicos);
        $this->assertSame($this->tenant->id, (int) $historicos->first()->tenant_id);

        $inscricoes = InscricaoDisciplina::where('matricula_id', $matricula->id)->get();
        $this->assertCount(1, $inscricoes);
        $this->assertSame($this->tenant->id, (int) $inscricoes->first()->tenant_id);

        // Nenhuma linha de A foi parar a B (e vice-versa).
        $this->assertSame(1, DB::table('matriculas')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, DB::table('matricula_historicos')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, DB::table('inscricoes_disciplinas')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_o_gerador_de_numero_continua_a_funcionar_com_dois_tenants(): void
    {
        $gerador = app(GeradorNumeroRegistoMatriculaService::class);

        $numeros = [];
        $numeros[] = $gerador->gerar();
        $numeros[] = $this->noTenant($this->outro, fn () => $gerador->gerar());
        $numeros[] = $gerador->gerar();
        $numeros[] = $this->noTenant($this->outro, fn () => $gerador->gerar());

        $this->assertCount(4, array_unique($numeros));
    }
}
