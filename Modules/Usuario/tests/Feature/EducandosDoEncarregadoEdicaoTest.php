<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Models\AuditoriaTenant;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** O funcionário acrescenta e remove educandos de um encarregado já criado, vendo o nome de cada aluno. */
class EducandosDoEncarregadoEdicaoTest extends TestCase
{
    use RefreshDatabase;

    private User $encarregado;

    /** @var array<string, Aluno> */
    private array $alunos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->value('id'));
        $this->actingAs($admin);

        foreach (['2026-0001' => 'Ana Silva', '2026-0002' => 'Rui Costa', '2026-0003' => 'Eva Dias'] as $matricula => $nome) {
            $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => 'BI' . $matricula, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
            $this->alunos[$matricula] = Aluno::create(['estabelecimento_id' => Estabelecimento::current()->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => $matricula]);
        }

        $this->encarregado = User::create(['name' => 'Pai', 'email' => 'pai@example.com', 'password' => Hash::make('x')]);
        $this->encarregado->roles()->attach(Role::where('nome', Perfil::ENCARREGADO->value)->value('id'));
        $this->encarregado->educandos()->attach($this->alunos['2026-0001']->dados_pessoa_id, ['parentesco' => 'Pai']);
        $this->encarregado->educandos()->attach($this->alunos['2026-0002']->dados_pessoa_id, ['parentesco' => 'Tio']);
    }

    private function pessoa(string $matricula): int
    {
        return $this->alunos[$matricula]->dados_pessoa_id;
    }

    private function actualizar(array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->put("/usuarios/{$this->encarregado->id}", ['name' => 'Pai', 'email' => 'pai@example.com', 'perfil' => 'encarregado'] + $extra);
    }

    public function test_editar_acrescenta_e_remove_educandos_e_mantem_o_parentesco_dos_que_ficam(): void
    {
        $this->actualizar(['matriculas_educandos' => ['2026-0002', '2026-0003']])->assertRedirect();

        $educandos = $this->encarregado->fresh()->educandos;
        $this->assertEqualsCanonicalizing([$this->pessoa('2026-0002'), $this->pessoa('2026-0003')], $educandos->pluck('id')->all());
        $this->assertSame('Tio', $educandos->firstWhere('id', $this->pessoa('2026-0002'))->pivot->parentesco);
    }

    public function test_exige_pelo_menos_um_educando_e_matriculas_que_existam(): void
    {
        $this->actualizar(['matriculas_educandos' => []])->assertSessionHasErrors('matriculas_educandos');
        $this->actualizar(['matriculas_educandos' => ['9999-0000']])->assertSessionHasErrors('matriculas_educandos.0');

        $this->assertCount(2, $this->encarregado->fresh()->educandos);
    }

    public function test_sem_o_campo_os_educandos_ficam_como_estao(): void
    {
        $this->actualizar([])->assertRedirect();

        $this->assertCount(2, $this->encarregado->fresh()->educandos);
    }

    public function test_a_alteracao_fica_na_auditoria_com_as_matriculas_antes_e_depois(): void
    {
        $this->actualizar(['matriculas_educandos' => ['2026-0003']])->assertRedirect();

        $linha = AuditoriaTenant::query()->where('accao', 'encarregado.educandos_alterados')->sole();
        $this->assertSame($this->encarregado->id, $linha->entidade_id);
        $this->assertEqualsCanonicalizing(['2026-0001', '2026-0002'], $linha->detalhe['antes']['matriculas']);
        $this->assertSame(['2026-0003'], $linha->detalhe['depois']['matriculas']);
    }

    public function test_sem_alteracao_nao_ha_auditoria(): void
    {
        $this->actualizar(['matriculas_educandos' => ['2026-0001', '2026-0002']])->assertRedirect();

        $this->assertSame(0, AuditoriaTenant::query()->where('accao', 'encarregado.educandos_alterados')->count());
    }

    public function test_o_ecra_de_edicao_recebe_os_educandos_com_nome_e_matricula(): void
    {
        $this->getJson("/usuarios/{$this->encarregado->id}/editar")->assertOk()->assertJson(fn ($json) => $json
            ->where('perfil', 'encarregado')
            ->has('educandos', 2)
            ->where('educandos.0', ['matricula' => '2026-0001', 'nome' => 'Ana Silva'])
            ->where('educandos.1', ['matricula' => '2026-0002', 'nome' => 'Rui Costa'])
            ->etc());
    }

    public function test_procurar_educando_devolve_o_nome_e_404_se_nao_existir(): void
    {
        $this->getJson('/usuarios/encarregados/procurar-educando?matricula=2026-0003')->assertOk()->assertJson([
            'nome' => 'Eva Dias', 'numero_matricula' => '2026-0003',
        ]);
        $this->getJson('/usuarios/encarregados/procurar-educando?matricula=2026-9999')->assertNotFound();
    }

    public function test_procurar_educando_exige_poder_criar_ou_editar_utilizadores(): void
    {
        $prof = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $prof->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->value('id'));

        $this->actingAs($prof)->getJson('/usuarios/encarregados/procurar-educando?matricula=2026-0003')->assertForbidden();
    }

    public function test_a_lista_de_encarregados_mostra_o_nome_dos_educandos(): void
    {
        $this->get('/usuarios/encarregados')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('usuarios.data.0.educandos', ['Ana Silva', 'Rui Costa']));
    }
}
