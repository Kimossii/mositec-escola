<?php

namespace Modules\Licenciamento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Licenciamento\Contracts\GarantiaDeLimites;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Core\Licenciamento\Exceptions\LimiteDeLicencaExcedido;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Licenciamento\Services\LimitesDeLicenca;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Actions\AlternarEstadoUsuarioAction;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class LimitesDeLicencaTest extends TestCase
{
    use ComLicencaDeTeste;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararLicencas();
        $this->congelarRelogioDeTeste();
    }

    private function instalarComLimites(?int $alunos, ?int $utilizadores = null): void
    {
        $lim = ['alunos' => $alunos, 'utilizadores' => $utilizadores];
        app(InstalarLicencaAction::class)->executar($this->tenant->id, $this->tenant->codigo, $this->tokenDeTeste(['lim' => $lim]));
    }

    private function criarAlunos(int $n, string $prefixo = 'A'): void
    {
        $estabelecimento = $this->estabelecimentoDeTeste();
        for ($i = 1; $i <= $n; $i++) {
            $pessoa = DadosPessoa::create(['nome_completo' => "Aluno {$prefixo}{$i}", 'numero_identificacao' => "{$prefixo}{$i}", 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
            Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => "{$prefixo}-000{$i}"]);
        }
    }

    private function criarUser(string $email, Perfil $perfil): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('x')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);

        return $user;
    }

    private function limites(): GarantiaDeLimites
    {
        return app(GarantiaDeLimites::class);
    }

    public function test_o_modulo_substitui_o_binding_nulo_do_core(): void
    {
        $this->assertInstanceOf(LimitesDeLicenca::class, $this->limites());
    }

    public function test_garante_dentro_do_limite_passa(): void
    {
        $this->instalarComLimites(3);
        $this->criarAlunos(2);

        $this->limites()->garantir(Recurso::ALUNOS);
        $this->addToAssertionCount(1);
    }

    public function test_no_limite_exacto_o_proximo_falha(): void
    {
        $this->instalarComLimites(2);
        $this->criarAlunos(2);

        try {
            $this->limites()->garantir(Recurso::ALUNOS);
            $this->fail('Devia ter excedido o limite.');
        } catch (LimiteDeLicencaExcedido $e) {
            $this->assertStringContainsString('2 alunos', $e->errors()['limite'][0]);
        }
    }

    public function test_adicionar_varios_de_uma_vez_conta_o_total(): void
    {
        $this->instalarComLimites(3);
        $this->criarAlunos(1);

        $this->limites()->garantir(Recurso::ALUNOS, 2);
        $this->expectException(LimiteDeLicencaExcedido::class);
        $this->limites()->garantir(Recurso::ALUNOS, 3);
    }

    public function test_limite_null_significa_sem_limite(): void
    {
        $this->instalarComLimites(null);
        $this->criarAlunos(3);

        $this->limites()->garantir(Recurso::ALUNOS, 1000);
        $this->addToAssertionCount(1);
    }

    public function test_sem_licenca_instalada_nao_aplica_limite(): void
    {
        $this->criarAlunos(3);

        $this->limites()->garantir(Recurso::ALUNOS);
        $this->addToAssertionCount(1);
    }

    public function test_flag_inactiva_nao_aplica(): void
    {
        $this->instalarComLimites(1);
        $this->criarAlunos(2);
        config(['licenciamento.activo' => false]);

        $this->limites()->garantir(Recurso::ALUNOS);
        $this->addToAssertionCount(1);
    }

    public function test_limite_de_utilizadores_ignora_contas_de_aluno(): void
    {
        $this->instalarComLimites(null, 2);
        $this->criarUser('a@x.test', Perfil::PROFESSOR);
        $this->criarUser('al1@x.test', Perfil::ALUNO);
        $this->criarUser('al2@x.test', Perfil::ALUNO);
        $this->criarUser('al3@x.test', Perfil::ALUNO);

        // 1 não-aluno + 1 a criar = 2: cabe, apesar das 3 contas de aluno.
        $this->limites()->garantir(Recurso::UTILIZADORES);

        $this->criarUser('b@x.test', Perfil::FUNCIONARIO);
        $this->expectException(LimiteDeLicencaExcedido::class);
        $this->limites()->garantir(Recurso::UTILIZADORES);
    }

    public function test_usuario_action_aplica_o_limite_so_a_contas_que_nao_sao_de_aluno(): void
    {
        $this->instalarComLimites(null, 1);
        $this->criarUser('a@x.test', Perfil::FUNCIONARIO);
        $dto = fn (Perfil $perfil, string $email) => new UsuarioDTO(name: 'N', password: 'segredo123', perfil: $perfil, tipoLogin: TipoLogin::EMAIL, email: $email);

        // Conta de aluno não gasta utilizadores, mesmo com o limite já cheio.
        app(UsuarioAction::class)->criar($dto(Perfil::ALUNO, 'aluno@x.test'));

        $this->expectException(LimiteDeLicencaExcedido::class);
        app(UsuarioAction::class)->criar($dto(Perfil::PROFESSOR, 'prof@x.test'));
    }

    public function test_aluno_com_perfil_de_staff_acumulado_continua_a_contar_e_aluno_puro_nao(): void
    {
        $this->instalarComLimites(null, 1);
        $prof = $this->criarUser('prof@x.test', Perfil::PROFESSOR);
        $this->criarUser('aluno@x.test', Perfil::ALUNO);
        $prof->roles()->attach(Role::where('nome', Perfil::ALUNO->value)->firstOrFail()->id);

        // Só o professor (mesmo com o perfil Aluno acrescentado) conta: 1 + 1 > 1.
        $this->expectException(LimiteDeLicencaExcedido::class);
        $this->limites()->garantir(Recurso::UTILIZADORES);
    }

    public function test_administrador_e_contas_inativas_nao_gastam_utilizadores_e_reactivar_respeita_o_limite(): void
    {
        $this->instalarComLimites(null, 1);
        $this->criarUser('admin@x.test', Perfil::ADMIN_ESCOLA);
        $prof = $this->criarUser('prof@x.test', Perfil::PROFESSOR)->fresh();

        // Administrador não conta: só o professor ocupa a única vaga.
        $this->assertSame(1, $this->limites()->utilizacao(Recurso::UTILIZADORES));

        // Inativo liberta a vaga.
        app(AlternarEstadoUsuarioAction::class)->executar($prof);
        $this->assertSame(0, $this->limites()->utilizacao(Recurso::UTILIZADORES));

        $this->criarUser('func@x.test', Perfil::FUNCIONARIO);

        // Reactivar o professor ultrapassaria o limite.
        $this->expectException(LimiteDeLicencaExcedido::class);
        app(AlternarEstadoUsuarioAction::class)->executar($prof->fresh());
    }

    public function test_aluno_inativo_nao_conta_para_o_limite_de_alunos(): void
    {
        $this->instalarComLimites(2);
        $this->criarAlunos(2);
        Aluno::query()->first()->update(['estado' => 0]);

        $this->assertSame(1, $this->limites()->utilizacao(Recurso::ALUNOS));
        $this->limites()->garantir(Recurso::ALUNOS);
        $this->addToAssertionCount(1);
    }

    public function test_a_mensagem_nao_vaza_dados_de_outro_tenant(): void
    {
        $this->instalarComLimites(2);
        $this->criarAlunos(2);
        $b = $this->criarTenant('MOSI-000002', 'Escola Secreta B', 'b.localhost');
        $this->noTenant($b, fn () => $this->criarAlunos(5, 'SEGREDO'));

        try {
            $this->limites()->garantir(Recurso::ALUNOS);
            $this->fail('Devia ter excedido o limite.');
        } catch (LimiteDeLicencaExcedido $e) {
            $texto = implode(' ', $e->errors()['limite']);
            $this->assertStringNotContainsString('SEGREDO', $texto);
            $this->assertStringNotContainsString('Escola Secreta', $texto);
            $this->assertStringNotContainsString('7', $texto);
        }

        // E os alunos do outro tenant não contam para este.
        $this->noTenant($b, function () {
            $this->limites()->garantir(Recurso::ALUNOS);
            $this->addToAssertionCount(1);
        });
    }
}
