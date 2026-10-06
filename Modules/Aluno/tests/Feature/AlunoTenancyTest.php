<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Models\AlunoEnquadramentoAcademico;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoTenancyTest extends TestCase
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

    private function pessoa(string $nome, string $bi): DadosPessoa
    {
        return DadosPessoa::create([
            'nome_completo' => $nome,
            'data_nascimento' => '2010-05-01',
            'numero_identificacao' => $bi,
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
    }

    private function aluno(string $nome, string $bi, string $numero): Aluno
    {
        return Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => $this->pessoa($nome, $bi)->id,
            'numero_matricula' => $numero,
        ]);
    }

    private function curso(string $codigo, string $nome): Curso
    {
        return Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome]);
    }

    private function nivel(string $codigo, string $nome): NivelAcademico
    {
        return NivelAcademico::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => $codigo, 'nome' => $nome, 'ordem' => 1, 'etapa_ensino' => 4]);
    }

    private function enquadramento(Aluno $aluno, Curso $curso): AlunoEnquadramentoAcademico
    {
        return AlunoEnquadramentoAcademico::create([
            'aluno_id' => $aluno->id,
            'curso_id' => $curso->id,
            'data_inicio' => '2026-01-01',
            'estado' => EstadoEnquadramentoAcademicoEnum::ACTIVO->value,
        ]);
    }

    private function dadosDeB(string $nome = 'Aluno B', string $numero = '2026-0001'): array
    {
        return $this->noTenant($this->outro, function () use ($nome, $numero) {
            $aluno = $this->aluno($nome, 'BI-B', $numero);
            $curso = $this->curso('C1', 'Curso B');

            return [
                'aluno' => $aluno, 'curso' => $curso, 'nivel' => $this->nivel('N1', 'Nivel B'),
                'enquadramento' => $this->enquadramento($aluno, $curso),
            ];
        });
    }

    private function dadosDeA(string $nome = 'Aluno A', string $numero = '2026-0001'): array
    {
        $aluno = $this->aluno($nome, 'BI-A', $numero);
        $curso = $this->curso('C1', 'Curso A');

        return ['aluno' => $aluno, 'curso' => $curso, 'enquadramento' => $this->enquadramento($aluno, $curso)];
    }

    public function test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->dadosDeA('Aluno So Em A', '2026-0001');
        $this->dadosDeB('Aluno So Em B', '2026-0002');

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/alunos?ano_lectivo_id='));
        $resposta->assertOk();
        $conteudo = $resposta->getContent();

        $this->assertStringContainsString('Aluno So Em A', $conteudo);
        $this->assertStringNotContainsString('Aluno So Em B', $conteudo);
    }

    public function test_pedir_no_dominio_de_a_um_id_de_b_da_404(): void
    {
        $admin = $this->administrador();
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/alunos/{$a['aluno']->id}"))->assertOk();

        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/alunos/{$b['aluno']->id}"))->assertNotFound();
        $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, "/alunos/{$b['aluno']->id}/resumo-academico"))->assertNotFound();
        $this->actingAs($admin)->patch($this->urlDoTenant($this->tenant, "/alunos/{$b['aluno']->id}/estado"), ['estado' => 0])->assertNotFound();
        $this->actingAs($admin)
            ->put($this->urlDoTenant($this->tenant, "/alunos/{$b['aluno']->id}"), [
                'nome_completo' => 'Invadido', 'data_nascimento' => '2010-01-01', 'numero_identificacao' => 'X1',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('alunos', ['id' => $b['aluno']->id, 'estado' => 1]);
        $this->assertDatabaseHas('dados_pessoas', ['id' => $b['aluno']->dados_pessoa_id, 'nome_completo' => 'Aluno B']);
    }

    public function test_exists_rejeita_o_id_de_outro_tenant(): void
    {
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();
        $pares = [
            'alunos' => [$a['aluno']->id, $b['aluno']->id],
            'aluno_enquadramentos_academicos' => [$a['enquadramento']->id, $b['enquadramento']->id],
        ];

        foreach ($pares as $tabela => [$idDeA, $idDeB]) {
            $this->assertTrue(Validator::make(['x' => $idDeA], ['x' => "exists:{$tabela},id"])->passes(), $tabela);
            $this->assertTrue(Validator::make(['x' => $idDeB], ['x' => "exists:{$tabela},id"])->fails(), $tabela);
        }
    }

    public function test_unique_e_por_tenant(): void
    {
        $this->aluno('Aluno A', 'BI-A', '2026-0001');

        // O mesmo número de matrícula em B é aceite (inserido pelo Model, com o tenant explícito).
        $this->noTenant($this->outro, fn () => $this->aluno('Aluno B', 'BI-B', '2026-0001'));
        $this->assertSame(1, Aluno::where('numero_matricula', '2026-0001')->count());

        // Duplicado dentro de A: recusado pela BD.
        try {
            $this->aluno('Duplicado', 'BI-DUP', '2026-0001');
            $this->fail('O duplicado dentro de A devia ser recusado pela BD.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        // Validação com o `where` e sem `where`: o VerificadorPresencaTenant filtra por tenant.
        $comWhere = fn (string $numero) => Validator::make(['n' => $numero], [
            'n' => ['required', Rule::unique('alunos', 'numero_matricula')->where(fn ($q) => $q->where('estabelecimento_id', Estabelecimento::current()->id))],
        ]);
        $semWhere = fn (string $numero) => Validator::make(['n' => $numero], ['n' => 'required|unique:alunos,numero_matricula']);

        $this->assertTrue($comWhere('2026-0001')->fails());
        $this->assertTrue($comWhere('2026-9999')->passes());
        $this->assertTrue($semWhere('2026-0001')->fails());
        $this->assertTrue($semWhere('2026-9999')->passes());

        // Um número que só existe em B é livre em A.
        $this->noTenant($this->outro, fn () => $this->aluno('So B', 'BI-SOB', '2026-7777'));
        $this->assertTrue($semWhere('2026-7777')->passes());
        $this->assertTrue($comWhere('2026-7777')->passes());
    }

    public function test_dados_pessoa_continua_unica_por_aluno_dentro_do_tenant(): void
    {
        $a = $this->dadosDeA();

        $this->expectException(QueryException::class);
        Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => $a['aluno']->dados_pessoa_id,
            'numero_matricula' => '2026-5555',
        ]);
    }

    public function test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao(): void
    {
        $a = $this->dadosDeA();

        foreach ([$a['aluno'], $a['enquadramento']] as $modelo) {
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

    public function test_a_action_de_criar_mantem_pessoa_e_aluno_no_tenant_do_contexto(): void
    {
        $this->dadosDeB('Aluno B', '2026-0001');

        $aluno = app(CriarAlunoAction::class)->executar(new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva',
            email: null,
            telefone: null,
            dataNascimento: '2010-05-01',
            sexo: DadosPessoa::SEXO_FEMININO,
            numeroIdentificacao: 'BI-NOVO',
        ));

        $this->assertSame($this->tenant->id, (int) $aluno->fresh()->tenant_id);
        $this->assertSame($this->tenant->id, (int) $aluno->dadosPessoa->fresh()->tenant_id);
        $this->assertSame(Estabelecimento::current()->id, $aluno->estabelecimento_id);
        $this->assertSame(1, Aluno::count());
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        $a = $this->dadosDeA();

        app(TenantContext::class)->limpar();

        $operacoes = [
            fn () => Aluno::query()->get(),
            fn () => AlunoEnquadramentoAcademico::query()->get(),
            fn () => $a['aluno']->update(['estado' => 0]),
            fn () => Aluno::create(['estabelecimento_id' => 1, 'dados_pessoa_id' => 1, 'numero_matricula' => 'X']),
            fn () => AlunoEnquadramentoAcademico::create(['aluno_id' => 1, 'data_inicio' => '2026-01-01', 'estado' => 1]),
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

        try {
            DB::table('alunos')->insert([
                'tenant_id' => $this->tenant->id,
                'estabelecimento_id' => $estabelecimentoDeB,
                'dados_pessoa_id' => $this->pessoa('Cruzado', 'BI-CRZ')->id,
                'numero_matricula' => '2026-0042',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
            $this->fail('A BD devia rejeitar o estabelecimento de outro tenant em alunos.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_a_relacao_nao_atravessa_tenants(): void
    {
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();

        $aluno = $a['aluno'];
        $aluno->forceFill(['dados_pessoa_id' => $b['aluno']->dados_pessoa_id, 'estabelecimento_id' => $b['aluno']->estabelecimento_id]);
        $this->assertNull($aluno->dadosPessoa);
        $this->assertNull($aluno->estabelecimento);

        $enquadramento = $a['enquadramento'];
        $enquadramento->forceFill(['aluno_id' => $b['aluno']->id, 'curso_id' => $b['curso']->id, 'nivel_academico_id' => $b['nivel']->id]);
        $this->assertNull($enquadramento->aluno);
        $this->assertNull($enquadramento->curso);
        $this->assertNull($enquadramento->nivelAcademico);

        // Relação inversa: o aluno de A só vê enquadramentos de A.
        $this->assertCount(1, $a['aluno']->fresh()->enquadramentosAcademicos);
    }

    public function test_aluno_nao_referencia_dados_pessoa_de_outro_tenant(): void
    {
        $admin = $this->administrador();
        $b = $this->dadosDeB();
        $pessoaDeB = $b['aluno']->dados_pessoa_id;
        $pessoaDeA = $this->pessoa('Pessoa A', 'BI-PA')->id;

        $this->assertTrue(Validator::make(['x' => $pessoaDeA], ['x' => 'exists:dados_pessoas,id'])->passes());
        $this->assertTrue(Validator::make(['x' => $pessoaDeB], ['x' => 'exists:dados_pessoas,id'])->fails());

        // HTTP: o FormRequest rejeita a pessoa de B e não grava.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/alunos'), ['dados_pessoa_id' => $pessoaDeB])
            ->assertSessionHasErrors('dados_pessoa_id');
        $this->assertSame(0, Aluno::count());

        // Controlo positivo: a pessoa de A é aceite.
        $this->actingAs($admin)
            ->post($this->urlDoTenant($this->tenant, '/alunos'), ['dados_pessoa_id' => $pessoaDeA])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Aluno::count());
        $this->assertSame($pessoaDeA, Aluno::first()->dados_pessoa_id);
    }
}
