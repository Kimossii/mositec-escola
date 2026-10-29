<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Actions\CriarUtilizadorDocenteAction;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * «Criar acesso» (decisão E6): a conta de um docente criada a partir do registo do docente, pelo botão em
 * Pessoal → Docentes ou escolhendo o docente em Utilizadores → Professores. Mesma Action nos dois sítios; nome e
 * pessoa vêm do servidor.
 */
class CriarAcessoDocenteTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private const URL_PROFESSORES = '/usuarios/professores/cadastrar';

    private const URL_PROCURAR = '/usuarios/professores/procurar-docente';

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

    /**
     * Utilizador sem perfil, só com as permissões indicadas (concessões individuais), autenticado.
     *
     * @param  list<array{0: Modulo, 1: string}>  $permissoes
     */
    private function comPermissoes(string $email, array $permissoes): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('x')]);

        foreach ($permissoes as [$modulo, $acao]) {
            UserPermissao::create([
                'users_id' => $u->id,
                'modulo_id' => ModuloRegistro::where('nome', $modulo->value)->firstOrFail()->id,
                'acao_id' => Acao::where('nome', $acao)->firstOrFail()->id,
                'permitido' => true,
            ]);
        }

        $this->actingAs($u);

        return $u;
    }

    private function corpo(array $sobre = []): array
    {
        return array_merge(['email' => 'acesso@example.com', 'password' => 'segredo123', 'password_confirmation' => 'segredo123'], $sobre);
    }

    private function contaDe(Docente $docente): ?User
    {
        return User::query()->where('dados_pessoa_id', $docente->dados_pessoa_id)->first();
    }

    public function test_cria_conta_professor_ligada_a_mesma_pessoa_com_dados_do_docente(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');

        $this->post(route('docentes.acesso', $docente), $this->corpo())->assertSessionHasNoErrors()->assertRedirect();

        $conta = $this->contaDe($docente);
        $this->assertNotNull($conta);
        $this->assertSame('Rita Docente', $conta->name);
        $this->assertSame('acesso@example.com', $conta->email);
        $this->assertSame(TipoLogin::EMAIL, $conta->tipo_login);
        $this->assertNull($conta->numero_matricula);
        $this->assertTrue(Hash::check('segredo123', $conta->password));
        $this->assertTrue($conta->roles->contains('nome', Perfil::PROFESSOR->value));
        $this->assertSame(1, $conta->roles()->count());
    }

    public function test_api_cria_conta_a_partir_do_docente_como_a_interface_web(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');

        $this->postJson('/api/v1/usuarios', ['perfil' => 'professor', 'docente_id' => $docente->id, 'email' => 'api@example.com', 'password' => 'segredo123', 'password_confirmation' => 'segredo123'])
            ->assertCreated();

        $conta = $this->contaDe($docente);
        $this->assertNotNull($conta);
        $this->assertSame('Rita Docente', $conta->name);
        $this->assertSame('api@example.com', $conta->email);
    }

    public function test_nao_cria_segundo_docente_e_tem_acesso_fica_verdadeiro(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');
        $pessoas = DadosPessoa::query()->count();

        $this->post(route('docentes.acesso', $docente), $this->corpo())->assertSessionHasNoErrors();

        $this->assertSame(1, Docente::query()->count());
        $this->assertSame($pessoas, DadosPessoa::query()->count());
        $this->assertTrue($docente->fresh()->temAcesso());
        $this->get(route('docentes.index'))->assertInertia(fn ($p) => $p->where('docentes.data.0.tem_acesso', true));
    }

    public function test_pode_gerir_passa_a_responder_pelo_novo_utilizador(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente('Rita Docente');
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01', 'data_fim' => null,
        ]);

        $this->post(route('docentes.acesso', $docente), $this->corpo())->assertSessionHasNoErrors();

        $conta = $this->contaDe($docente);
        $this->assertTrue(app(VerificaAtribuicaoDocente::class)->podeGerir($conta->id, $c['turma']->id, $c['disciplina']->id, Carbon::parse('2026-06-15')));
    }

    public function test_segundo_acesso_e_rejeitado(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');
        $this->post(route('docentes.acesso', $docente), $this->corpo())->assertSessionHasNoErrors();

        $this->post(route('docentes.acesso', $docente), $this->corpo(['email' => 'outro@example.com']))->assertSessionHasErrors('docente_id');

        $this->assertSame(1, User::query()->where('dados_pessoa_id', $docente->dados_pessoa_id)->count());
        $this->assertDatabaseMissing('users', ['email' => 'outro@example.com']);
    }

    public function test_nome_e_pessoa_enviados_pelo_cliente_sao_ignorados(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');
        $outraPessoa = DadosPessoa::create(['nome_completo' => 'Outra', 'numero_identificacao' => 'BI-OUTRA', 'tipo_pessoa' => DadosPessoa::TIPO_OUTRO]);

        $this->post(route('docentes.acesso', $docente), $this->corpo([
            'name' => 'Falso', 'nome_completo' => 'Falso', 'dados_pessoa_id' => $outraPessoa->id, 'perfil' => 'admin_escola', 'tipo_login' => 'matricula',
        ]))->assertSessionHasNoErrors();

        $conta = $this->contaDe($docente);
        $this->assertSame('Rita Docente', $conta->name);
        $this->assertSame(TipoLogin::EMAIL, $conta->tipo_login);
        $this->assertTrue($conta->roles->contains('nome', Perfil::PROFESSOR->value));
        $this->assertFalse($conta->roles->contains('nome', Perfil::ADMIN_ESCOLA->value));
        $this->assertDatabaseMissing('users', ['name' => 'Falso']);
        $this->assertDatabaseMissing('users', ['dados_pessoa_id' => $outraPessoa->id]);
    }

    public function test_valida_email_e_senha_e_rejeita_email_repetido(): void
    {
        $eu = $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');

        $this->post(route('docentes.acesso', $docente), [])->assertSessionHasErrors(['email', 'password']);
        $this->post(route('docentes.acesso', $docente), $this->corpo(['email' => 'invalido']))->assertSessionHasErrors('email');
        $this->post(route('docentes.acesso', $docente), $this->corpo(['password_confirmation' => 'outra']))->assertSessionHasErrors('password');
        $this->post(route('docentes.acesso', $docente), $this->corpo(['email' => $eu->email]))->assertSessionHasErrors('email');

        $this->assertFalse($docente->fresh()->temAcesso());
    }

    public function test_a_action_recusa_email_em_uso_mesmo_sem_o_form_request(): void
    {
        $eu = $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');

        try {
            app(CriarUtilizadorDocenteAction::class)->executar($docente->id, $eu->email, 'segredo123');
            $this->fail('Devia recusar o email em uso.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->assertFalse($docente->fresh()->temAcesso());
    }

    public function test_docente_inactivo_e_rejeitado(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');
        $docente->update(['estado' => 0]);

        $this->post(route('docentes.acesso', $docente), $this->corpo())->assertSessionHasErrors('docente_id');

        $this->assertFalse($docente->fresh()->temAcesso());
    }

    public function test_sem_usuario_criar_ou_sem_docente_editar_e_proibido(): void
    {
        $docente = $this->criarDocente('Rita Docente');

        $this->como(Perfil::PROFESSOR);
        $this->post(route('docentes.acesso', $docente), $this->corpo())->assertForbidden();

        $soDocentes = $this->comPermissoes('so-docentes@example.com', [[Modulo::DOCENTE, 'ver'], [Modulo::DOCENTE, 'editar']]);
        $this->assertTrue($soDocentes->can('docente.editar'));
        $this->assertFalse($soDocentes->can('usuario.criar'));
        $this->post(route('docentes.acesso', $docente), $this->corpo())->assertForbidden();

        $soContas = $this->comPermissoes('so-contas@example.com', [[Modulo::USUARIO, 'criar']]);
        $this->assertFalse($soContas->can('docente.editar'));
        $this->post(route('docentes.acesso', $docente), $this->corpo())->assertForbidden();

        $this->assertFalse($docente->fresh()->temAcesso());
    }

    public function test_docente_de_outro_tenant_da_404(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $alheio = $this->noTenant($outro, fn () => $this->criarDocente('Alheio'));

        $this->post(route('docentes.acesso', $alheio->id), $this->corpo())->assertNotFound();

        $this->assertFalse($this->noTenant($outro, fn () => $alheio->fresh()->temAcesso()));
    }

    public function test_pelo_fluxo_de_utilizadores_com_docente_id_equivale_ao_botao(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');

        $this->post(self::URL_PROFESSORES, $this->corpo([
            'perfil' => 'professor', 'docente_id' => $docente->id, 'name' => 'Falso', 'tipo_login' => 'matricula', 'estado' => 1,
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $conta = $this->contaDe($docente);
        $this->assertNotNull($conta);
        $this->assertSame('Rita Docente', $conta->name);
        $this->assertSame('acesso@example.com', $conta->email);
        $this->assertSame(TipoLogin::EMAIL, $conta->tipo_login);
        $this->assertTrue($conta->roles->contains('nome', Perfil::PROFESSOR->value));
        $this->assertSame(1, Docente::query()->count());

        // Segunda vez: já tem conta.
        $this->post(self::URL_PROFESSORES, $this->corpo(['perfil' => 'professor', 'docente_id' => $docente->id, 'email' => 'b@example.com']))
            ->assertSessionHasErrors('docente_id');
    }

    public function test_fluxo_de_utilizadores_com_docente_id_exige_tambem_docente_editar(): void
    {
        $docente = $this->criarDocente('Rita Docente');
        $pedido = $this->corpo(['perfil' => 'professor', 'docente_id' => $docente->id]);

        $this->comPermissoes('so-contas@example.com', [[Modulo::USUARIO, 'criar']]);
        $this->post(self::URL_PROFESSORES, $pedido)->assertForbidden();
        $this->assertFalse($docente->fresh()->temAcesso());

        $this->comPermissoes('ambas@example.com', [[Modulo::USUARIO, 'criar'], [Modulo::DOCENTE, 'editar']]);
        $this->post(self::URL_PROFESSORES, $pedido)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($docente->fresh()->temAcesso());
    }

    public function test_dados_pessoa_id_do_cliente_e_ignorado_e_nao_liga_conta_ao_docente(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $docente = $this->criarDocente('Rita Docente');

        foreach (['funcionario'] as $i => $perfil) {
            $this->post('/usuarios/cadastrarUsuario', $this->corpo([
                'perfil' => $perfil, 'name' => 'Intruso ' . $i, 'tipo_login' => 'email', 'email' => "intruso{$i}@example.com",
                'dados_pessoa_id' => $docente->dados_pessoa_id,
            ]))->assertSessionHasNoErrors();

            $conta = User::query()->where('email', "intruso{$i}@example.com")->firstOrFail();
            $this->assertNotSame($docente->dados_pessoa_id, $conta->dados_pessoa_id);
        }

        $this->assertFalse($docente->fresh()->temAcesso());
        $this->assertSame(0, User::query()->where('dados_pessoa_id', $docente->dados_pessoa_id)->count());
    }

    public function test_fluxo_de_utilizadores_rejeita_inactivo_inexistente_e_de_outro_tenant(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $inactivo = $this->criarDocente('Inactivo');
        $inactivo->update(['estado' => 0]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $alheio = $this->noTenant($outro, fn () => $this->criarDocente('Alheio'));

        foreach ([$inactivo->id, $alheio->id, 999999] as $id) {
            $this->post(self::URL_PROFESSORES, $this->corpo(['perfil' => 'professor', 'docente_id' => $id]))->assertSessionHasErrors('docente_id');
        }

        $this->assertSame(1, User::query()->count()); // só o autenticado
    }

    public function test_professor_sem_docente_id_e_recusado_e_nada_e_criado(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->post(self::URL_PROFESSORES, $this->corpo(['perfil' => 'professor', 'name' => 'Novo Prof', 'tipo_login' => 'email']))
            ->assertSessionHasErrors('docente_id');

        $this->assertNull(User::query()->where('email', 'acesso@example.com')->first());
        $this->assertSame(0, Docente::query()->count());
    }

    public function test_procura_de_docentes_sem_conta(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $semConta = $this->criarDocente('Ana Sem Conta');
        $semConta->dadosPessoa->update(['email' => 'ana@escola.ao']);
        $this->criarDocente('Ana Com Conta', comAcesso: true);
        $this->criarDocente('Ana Inactiva')->update(['estado' => 0]);
        $this->criarDocente('Bruno Sem Conta');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->criarDocente('Ana Alheia'));

        $this->getJson(self::URL_PROCURAR . '?pesquisa=ana')
            ->assertOk()
            ->assertExactJson(['docentes' => [[
                'id' => $semConta->id,
                'nome' => 'Ana Sem Conta',
                'numero_identificacao' => $semConta->dadosPessoa->numero_identificacao,
                'email' => 'ana@escola.ao',
            ]]]);

        $this->getJson(self::URL_PROCURAR)->assertOk()->assertJsonCount(2, 'docentes');
        $this->getJson(self::URL_PROCURAR . '?pesquisa=' . $semConta->dadosPessoa->numero_identificacao)->assertJsonCount(1, 'docentes');
    }

    public function test_procura_exige_usuario_criar_e_esconde_identificacao_sem_docente_ver(): void
    {
        $docente = $this->criarDocente('Ana Sem Conta');

        $this->como(Perfil::PROFESSOR);
        $this->getJson(self::URL_PROCURAR)->assertForbidden();

        $this->comPermissoes('so-contas@example.com', [[Modulo::USUARIO, 'criar'], [Modulo::DOCENTE, 'editar']]);

        $this->getJson(self::URL_PROCURAR)->assertOk()->assertExactJson(['docentes' => [['id' => $docente->id, 'nome' => 'Ana Sem Conta']]]);
    }

    public function test_procurar_docente_exige_tambem_docente_editar(): void
    {
        $this->comPermissoes('so-criar@example.com', [[Modulo::USUARIO, 'criar']]);

        $this->getJson(self::URL_PROCURAR . '?q=ana')->assertForbidden();
    }
}
