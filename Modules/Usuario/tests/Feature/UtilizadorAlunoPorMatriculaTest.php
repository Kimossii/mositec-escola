<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Modules\Aluno\Services\ProcuraAlunoParaContaService;
use Modules\Core\Contracts\ProcuraAlunoParaConta;
use Modules\Core\Contracts\ProcuraSituacaoAcademicaDoAluno;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Matricula\Services\SituacaoAcademicaDoAlunoService;
use Modules\Turma\Models\NivelAcademico;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\MatriculaSequencia;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Cadastro de utilizador com perfil aluno: o login é o número de matrícula OFICIAL do registo do
 * aluno e tudo o que identifica a pessoa vem do servidor, nunca do cliente.
 */
class UtilizadorAlunoPorMatriculaTest extends TestCase
{
    use RefreshDatabase;

    private const URL_CRIAR = '/usuarios/alunos/cadastrar';
    private const URL_PROCURAR = '/usuarios/alunos/procurar-matricula';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('segredo123')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        return $staff;
    }

    /** Tem usuario.criar mas NÃO aluno.ver. */
    private function actingAsFuncionario(): User
    {
        $f = User::create(['name' => 'Func', 'email' => 'func@example.com', 'password' => Hash::make('segredo123')]);
        $f->roles()->syncWithoutDetaching([Role::where('nome', Perfil::FUNCIONARIO->value)->first()->id]);
        $this->actingAs($f);

        return $f;
    }

    /** @return array{ano: AnoLectivo, nivel: NivelAcademico, curso: Curso, turma: Turma} */
    private function estruturaAcademica(string $m = 'A', string $anoNome = '2026'): array
    {
        $estId = Estabelecimento::current()->id;
        $ano = AnoLectivo::create(['estabelecimento_id' => $estId, 'nome' => $anoNome, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estId, 'codigo' => "N{$m}", 'nome' => "Nivel {$m}", 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 1]);
        $curso = Curso::create(['estabelecimento_id' => $estId, 'codigo' => "C{$m}", 'nome' => "Curso {$m}"]);
        $turma = Turma::create(['ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => "T{$m}", 'nome' => "Turma {$m}"]);

        return compact('ano', 'nivel', 'curso', 'turma');
    }

    private function matricular(Aluno $aluno, Turma $turma, int $estado, string $data = '2026-02-01', ?string $registo = null): Matricula
    {
        return Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $turma->ano_lectivo_id,
            'numero_registo_matricula' => $registo ?? 'REG-' . $aluno->id . '-' . $data . '-' . $estado,
            'data_matricula' => $data, 'estado' => $estado,
        ]);
    }

    private function criarAluno(string $matricula = '2026-0001', string $nome = 'Ana Silva', int $estado = 1): Aluno
    {
        $pessoa = DadosPessoa::create([
            'nome_completo' => $nome,
            'numero_identificacao' => 'BI' . $matricula,
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);

        return Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => $matricula,
            'estado' => $estado,
        ]);
    }

    private function dadosDeCriacao(string $matricula, array $extra = []): array
    {
        return array_merge([
            'perfil' => 'aluno',
            'numero_matricula' => $matricula,
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
        ], $extra);
    }

    public function test_o_nome_enviado_pelo_cliente_e_ignorado_e_fica_o_do_registo_do_aluno(): void
    {
        $this->actingAsStaff();
        $aluno = $this->criarAluno('2026-0001', 'Ana Silva');

        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001', ['name' => 'Falso']))->assertRedirect();

        $user = User::where('numero_matricula', '2026-0001')->firstOrFail();
        $this->assertSame('Ana Silva', $user->name);
        $this->assertDatabaseMissing('users', ['name' => 'Falso']);
        $this->assertSame($aluno->dados_pessoa_id, $user->dados_pessoa_id);
        $this->assertTrue($user->roles->contains('nome', Perfil::ALUNO->value));
    }

    public function test_email_dados_pessoa_id_e_tipo_login_do_cliente_sao_ignorados(): void
    {
        $this->actingAsStaff();
        $aluno = $this->criarAluno('2026-0001');
        $outraPessoa = DadosPessoa::create(['nome_completo' => 'Outra Pessoa', 'numero_identificacao' => 'BIOUTRA', 'tipo_pessoa' => DadosPessoa::TIPO_OUTRO]);

        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001', [
            'name' => 'Falso',
            'email' => 'falso@example.com',
            'tipo_login' => 'email',
            'dados_pessoa_id' => $outraPessoa->id,
        ]))->assertRedirect();

        $user = User::where('numero_matricula', '2026-0001')->firstOrFail();
        $this->assertNull($user->email);
        $this->assertSame(TipoLogin::MATRICULA, $user->tipo_login);
        $this->assertSame($aluno->dados_pessoa_id, $user->dados_pessoa_id);
        $this->assertNotSame($outraPessoa->id, $user->dados_pessoa_id);
        $this->assertDatabaseMissing('users', ['email' => 'falso@example.com']);
    }

    public function test_o_login_e_a_matricula_oficial_e_nenhum_numero_novo_e_gerado(): void
    {
        $this->actingAsStaff();
        $aluno = $this->criarAluno('2026-0042');

        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0042'))->assertRedirect();

        $user = User::where('dados_pessoa_id', $aluno->dados_pessoa_id)->firstOrFail();
        $this->assertSame($aluno->numero_matricula, $user->numero_matricula);
        $this->assertSame(1, User::where('numero_matricula', 'like', '____-____')->count());
        $this->assertSame(0, MatriculaSequencia::count(), 'A sequência de matrículas não pode ser tocada para alunos.');
    }

    public function test_aluno_inactivo_e_recusado(): void
    {
        $this->actingAsStaff();
        $this->criarAluno('2026-0001', 'Ana Silva', 0);

        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001'))->assertSessionHasErrors('numero_matricula');

        $this->assertDatabaseMissing('users', ['numero_matricula' => '2026-0001']);
    }

    public function test_segundo_utilizador_para_o_mesmo_aluno_e_recusado_com_mensagem_clara(): void
    {
        $this->actingAsStaff();
        $this->criarAluno('2026-0001');

        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001'))->assertRedirect();
        $resposta = $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001'));

        $resposta->assertSessionHasErrors(['numero_matricula' => 'Este aluno já tem conta.']);
        $this->assertSame(1, User::where('numero_matricula', '2026-0001')->count());
    }

    public function test_o_indice_unico_e_a_segunda_barreira_contra_contas_duplicadas(): void
    {
        $this->actingAsStaff();
        User::create(['name' => 'A', 'numero_matricula' => '2026-0001', 'tipo_login' => TipoLogin::MATRICULA, 'password' => Hash::make('x')]);

        $this->expectException(QueryException::class);

        User::create(['name' => 'B', 'numero_matricula' => '2026-0001', 'tipo_login' => TipoLogin::MATRICULA, 'password' => Hash::make('x')]);
    }

    public function test_matricula_inexistente_e_de_outra_escola_dao_a_mesma_mensagem(): void
    {
        $this->actingAsStaff();
        $escolaB = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($escolaB, fn () => $this->criarAluno('2026-0099', 'Aluno de B'));

        $inexistente = $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-7777'));
        $deOutraEscola = $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0099'));

        $inexistente->assertSessionHasErrors('numero_matricula');
        $deOutraEscola->assertSessionHasErrors('numero_matricula');
        $this->assertSame(
            $inexistente->getSession()->get('errors')->get('numero_matricula'),
            $deOutraEscola->getSession()->get('errors')->get('numero_matricula'),
        );
        $this->assertSame(0, User::where('numero_matricula', '2026-0099')->count());
        $this->noTenant($escolaB, fn () => $this->assertSame(0, User::where('numero_matricula', '2026-0099')->count()));
    }

    public function test_aluno_de_outro_tenant_nao_e_encontrado_pelo_endpoint_e_o_404_e_identico(): void
    {
        $this->actingAsStaff();
        $escolaB = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($escolaB, fn () => $this->criarAluno('2026-0099', 'Aluno de B'));

        $deB = $this->getJson(self::URL_PROCURAR . '?matricula=2026-0099');
        $inexistente = $this->getJson(self::URL_PROCURAR . '?matricula=2026-7777');

        $deB->assertNotFound();
        $inexistente->assertNotFound();
        $this->assertSame($inexistente->getContent(), $deB->getContent());
        $this->assertStringNotContainsString('Aluno de B', $deB->getContent());
    }

    public function test_sem_aluno_ver_o_endpoint_devolve_exactamente_as_quatro_chaves(): void
    {
        $this->actingAsFuncionario();
        $this->criarAluno('2026-0001', 'Ana Silva');

        $resposta = $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001');

        $resposta->assertOk();
        $this->assertSame(['estado', 'estado_descricao', 'ja_tem_conta', 'nome'], collect($resposta->json())->keys()->sort()->values()->all());
        $resposta->assertExactJson([
            'nome' => 'Ana Silva',
            'estado' => 1,
            'estado_descricao' => 'Ativo',
            'ja_tem_conta' => false,
        ]);
    }

    public function test_com_aluno_ver_a_resposta_tem_as_oito_chaves_com_os_valores_certos(): void
    {
        $this->actingAsStaff();
        $aluno = $this->criarAluno('2026-0001', 'Ana Silva');
        $aluno->dadosPessoa()->first()->update(['data_nascimento' => '2010-03-05']);
        $e = $this->estruturaAcademica('10A');
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, cursoId: $e['curso']->id);
        $this->matricular($aluno, $e['turma'], EstadoMatriculaEnum::ACTIVA->value);

        $resposta = $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertOk();

        $resposta->assertExactJson([
            'nome' => 'Ana Silva',
            'estado' => 1,
            'estado_descricao' => 'Ativo',
            'ja_tem_conta' => false,
            'data_nascimento' => '2010-03-05',
            'numero_identificacao' => 'BI2026-0001',
            'curso' => 'Curso 10A',
            'turma' => 'Turma 10A (2026)',
        ]);
    }

    public function test_sem_matricula_nem_enquadramento_curso_e_turma_sao_nulos(): void
    {
        $this->actingAsStaff();
        $this->criarAluno('2026-0001');

        $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertOk()
            ->assertJsonPath('curso', null)->assertJsonPath('turma', null)->assertJsonPath('data_nascimento', null);
    }

    public function test_so_conta_a_matricula_activa_mais_recente(): void
    {
        $this->actingAsStaff();
        $aluno = $this->criarAluno('2026-0001');
        $velha = $this->estruturaAcademica('V', '2025');
        $nova = $this->estruturaAcademica('N', '2026');
        $inactiva = $this->estruturaAcademica('I', '2027');
        $this->matricular($aluno, $velha['turma'], EstadoMatriculaEnum::ACTIVA->value, '2025-02-01');
        $this->matricular($aluno, $nova['turma'], EstadoMatriculaEnum::ACTIVA->value, '2026-02-01');
        $this->matricular($aluno, $inactiva['turma'], EstadoMatriculaEnum::CANCELADA->value, '2027-02-01');
        $this->matricular($aluno, $inactiva['turma'], EstadoMatriculaEnum::PENDENTE->value, '2027-03-01');

        $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertOk()
            ->assertJsonPath('turma', 'Turma N (2026)');
    }

    public function test_so_com_matriculas_inactivas_a_turma_e_nula(): void
    {
        $this->actingAsStaff();
        $aluno = $this->criarAluno('2026-0001');
        $e = $this->estruturaAcademica();
        $this->matricular($aluno, $e['turma'], EstadoMatriculaEnum::CONCLUIDA->value);

        $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertOk()->assertJsonPath('turma', null)->assertJsonPath('curso', null);
    }

    public function test_sem_curso_no_enquadramento_usa_o_curso_da_turma(): void
    {
        $this->actingAsStaff();
        $aluno = $this->criarAluno('2026-0001');
        $e = $this->estruturaAcademica('Z');
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $e['nivel']->id);
        $this->matricular($aluno, $e['turma'], EstadoMatriculaEnum::ACTIVA->value);

        $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertOk()->assertJsonPath('curso', 'Curso Z');
    }

    public function test_aluno_de_outro_tenant_nao_revela_dados_academicos_nem_pessoais(): void
    {
        $this->actingAsStaff();
        $escolaB = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($escolaB, function () {
            $aluno = $this->criarAluno('2026-0099', 'Aluno de B');
            $e = $this->estruturaAcademica('BBB');
            $this->matricular($aluno, $e['turma'], EstadoMatriculaEnum::ACTIVA->value);
        });

        $resposta = $this->getJson(self::URL_PROCURAR . '?matricula=2026-0099');

        $resposta->assertNotFound();
        $this->assertStringNotContainsString('BBB', $resposta->getContent());
        $this->assertStringNotContainsString('BI2026-0099', $resposta->getContent());
    }

    public function test_curso_e_turma_de_outro_tenant_nunca_aparecem(): void
    {
        $this->actingAsStaff();
        $aluno = $this->criarAluno('2026-0001');
        $escolaB = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $deB = $this->noTenant($escolaB, fn () => $this->estruturaAcademica('BBB'));
        // Referências de A para registos de B (dados inconsistentes): o scope tem de os esconder.
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, cursoId: $deB['curso']->id);
        $this->matricular($aluno, $deB['turma'], EstadoMatriculaEnum::ACTIVA->value);

        $resposta = $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertOk();

        $resposta->assertJsonPath('curso', null)->assertJsonPath('turma', null);
        $this->assertStringNotContainsString('BBB', $resposta->getContent());
    }

    public function test_o_contrato_da_situacao_academica_esta_ligado_ao_modulo_matricula(): void
    {
        $this->assertInstanceOf(SituacaoAcademicaDoAlunoService::class, app(ProcuraSituacaoAcademicaDoAluno::class));
    }

    public function test_o_endpoint_indica_quando_o_aluno_ja_tem_conta(): void
    {
        $this->actingAsStaff();
        $this->criarAluno('2026-0001');
        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001'))->assertRedirect();

        $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertOk()->assertJsonPath('ja_tem_conta', true);
    }

    public function test_o_endpoint_exige_autenticacao_e_permissao(): void
    {
        $this->criarAluno('2026-0001');

        $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertUnauthorized();
        $this->get(self::URL_PROCURAR . '?matricula=2026-0001')->assertRedirect();

        $semPermissao = User::create(['name' => 'Sem', 'email' => 'sem@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($semPermissao)->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertForbidden();
    }

    public function test_a_rota_de_pesquisa_tem_throttle_e_permissao(): void
    {
        $rota = Route::getRoutes()->getByName('usuario.alunos.procurar');

        $this->assertNotNull($rota);
        $this->assertContains('throttle:30,1', $rota->gatherMiddleware());
        $this->assertContains('can:usuario.criar', $rota->gatherMiddleware());
    }

    public function test_a_correspondencia_e_exacta(): void
    {
        $this->actingAsStaff();
        $this->criarAluno('2026-0001');

        $this->getJson(self::URL_PROCURAR . '?matricula=2026-000')->assertNotFound();
        $this->getJson(self::URL_PROCURAR . '?matricula=026-0001')->assertNotFound();
        $this->getJson(self::URL_PROCURAR . '?matricula=2026-0001')->assertOk();
    }

    public function test_matricula_vazia_ou_invalida_da_422(): void
    {
        $this->actingAsStaff();

        $this->getJson(self::URL_PROCURAR)->assertStatus(422);
        $this->getJson(self::URL_PROCURAR . '?matricula=')->assertStatus(422);
        $this->getJson(self::URL_PROCURAR . '?matricula=' . urlencode("2026%' OR 1=1 --"))->assertStatus(422);
        $this->getJson(self::URL_PROCURAR . '?matricula=' . str_repeat('9', 80))->assertStatus(422);
    }

    public function test_para_aluno_a_matricula_e_obrigatoria_e_nome_e_email_nao(): void
    {
        $this->actingAsStaff();

        $this->post(self::URL_CRIAR, [
            'perfil' => 'aluno',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
        ])->assertSessionHasErrors('numero_matricula')->assertSessionDoesntHaveErrors(['name', 'email', 'tipo_login']);
    }

    public function test_a_senha_continua_confirmada_com_minimo_de_6(): void
    {
        $this->actingAsStaff();
        $this->criarAluno('2026-0001');

        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001', ['password' => '123', 'password_confirmation' => '123']))
            ->assertSessionHasErrors('password');
        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001', ['password_confirmation' => 'outra123']))
            ->assertSessionHasErrors('password');
        $this->assertSame(0, User::where('numero_matricula', '2026-0001')->count());
    }

    public function test_os_outros_perfis_continuam_a_exigir_nome_e_email(): void
    {
        $this->actingAsStaff();

        $this->post('/usuarios/professores/cadastrar', [
            'perfil' => 'professor',
            'tipo_login' => 'email',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
        ])->assertSessionHasErrors(['name', 'email']);
    }

    public function test_na_actualizacao_o_nome_enviado_para_um_aluno_e_ignorado(): void
    {
        $this->actingAsStaff();
        $this->criarAluno('2026-0001', 'Ana Silva');
        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001'))->assertRedirect();
        $user = User::where('numero_matricula', '2026-0001')->firstOrFail();

        $this->put("/usuarios/{$user->id}", ['name' => 'Falso', 'email' => 'falso@example.com', 'perfil' => 'aluno'])->assertRedirect();

        $user->refresh();
        $this->assertSame('Ana Silva', $user->name);
        $this->assertNull($user->email);
    }

    public function test_a_actualizacao_de_outros_perfis_mantem_o_comportamento(): void
    {
        $this->actingAsStaff();
        $prof = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $prof->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->first()->id);

        $this->put("/usuarios/{$prof->id}", ['name' => 'Prof Novo', 'email' => 'novo@example.com', 'perfil' => 'professor'])->assertRedirect();

        $this->assertSame('Prof Novo', $prof->fresh()->name);
        $this->assertSame('novo@example.com', $prof->fresh()->email);
    }

    public function test_o_encarregado_liga_o_educando_pela_matricula_oficial_do_utilizador_aluno(): void
    {
        $this->actingAsStaff();
        $this->criarAluno('2026-0001');
        $this->post(self::URL_CRIAR, $this->dadosDeCriacao('2026-0001'))->assertRedirect();

        $this->post('/usuarios/encarregados/cadastrar', [
            'name' => 'Encarregado',
            'perfil' => 'encarregado',
            'tipo_login' => 'email',
            'email' => 'enc@example.com',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
            'matriculas_educandos' => ['2026-0001'],
        ])->assertRedirect();

        $encarregado = User::where('email', 'enc@example.com')->firstOrFail();
        $this->assertSame(['2026-0001'], $encarregado->educandos->pluck('numero_matricula')->all());
    }

    public function test_o_contrato_esta_ligado_a_implementacao_do_modulo_aluno(): void
    {
        $this->assertInstanceOf(ProcuraAlunoParaContaService::class, app(ProcuraAlunoParaConta::class));
    }
}
