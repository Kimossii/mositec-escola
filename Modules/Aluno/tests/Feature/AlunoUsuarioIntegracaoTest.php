<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoUsuarioIntegracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);

        // O UsuarioAction::criar chama sempre GarantirAdministradorEfetivoAction,
        // que exige pelo menos um utilizador com autorizacao.editar no sistema —
        // este admin garante essa invariante antes de qualquer teste desta classe.
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
    }

    private function criarAluno(string $numeroIdentificacao, string $numeroMatricula): Aluno
    {
        $estabelecimento = Estabelecimento::current() ?? Estabelecimento::create([
            'nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true,
        ]);
        $pessoa = DadosPessoal::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => $numeroIdentificacao,
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);

        return Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => $numeroMatricula,
        ]);
    }

    public function test_aluno_pode_existir_sem_user(): void
    {
        $aluno = $this->criarAluno('BI0001', '2026-0001');

        $this->assertSame(0, User::where('dados_pessoa_id', $aluno->dados_pessoa_id)->count());
    }

    public function test_aluno_pode_receber_user_reaproveitando_o_fluxo_do_modulo_usuario(): void
    {
        $aluno = $this->criarAluno('BI0001', '2026-0001');

        $dto = new UsuarioDTO(
            name: 'Ana Silva',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $aluno->dados_pessoa_id,
            numeroMatricula: $aluno->numero_matricula,
        );

        $user = app(UsuarioAction::class)->criar($dto);

        $this->assertSame($aluno->dados_pessoa_id, $user->dados_pessoa_id);
        $this->assertSame($aluno->numero_matricula, $user->numero_matricula, 'numero_matricula do User deve ser o mesmo do Aluno — fonte unica');
        $this->assertTrue($user->roles()->where('nome', Perfil::ALUNO->value)->exists());
    }

    public function test_uma_pessoa_nao_pode_ter_duas_contas(): void
    {
        $aluno = $this->criarAluno('BI0001', '2026-0001');

        $dto = fn () => new UsuarioDTO(
            name: 'Ana Silva',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $aluno->dados_pessoa_id,
            numeroMatricula: $aluno->numero_matricula,
        );

        app(UsuarioAction::class)->criar($dto());

        $this->expectException(QueryException::class);
        app(UsuarioAction::class)->criar(new UsuarioDTO(
            name: 'Ana Silva 2',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $aluno->dados_pessoa_id,
            numeroMatricula: '2026-9999',
        ));
    }

    public function test_criar_user_para_aluno_nao_cria_outro_aluno(): void
    {
        $aluno = $this->criarAluno('BI0001', '2026-0001');

        app(UsuarioAction::class)->criar(new UsuarioDTO(
            name: 'Ana Silva',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $aluno->dados_pessoa_id,
            numeroMatricula: $aluno->numero_matricula,
        ));

        $this->assertSame(1, Aluno::count());
    }

    public function test_user_criado_primeiro_pode_depois_ser_associado_a_um_aluno_da_mesma_pessoa(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Carlos Neto', 'numero_identificacao' => 'BI0003', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);

        $user = app(UsuarioAction::class)->criar(new UsuarioDTO(
            name: 'Carlos Neto',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $pessoa->id,
            numeroMatricula: '2026-0003',
        ));

        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => $user->numero_matricula,
        ]);

        $this->assertSame($pessoa->id, $aluno->dados_pessoa_id);
        $this->assertSame($pessoa->id, $user->dados_pessoa_id);
        $this->assertSame(1, DadosPessoal::count());
        $this->assertSame(1, User::where('dados_pessoa_id', $pessoa->id)->count());
    }
}
