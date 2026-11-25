<?php

namespace Modules\Portal\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** O encarregado vê as faltas do filho, e só as do filho. */
class PortalFaltasTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->c = $this->cenarioDeFrequencia();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function conta(Perfil $perfil, string $email, ?int $pessoaId = null): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('x'), 'dados_pessoa_id' => $pessoaId]);
        $u->roles()->attach(Role::where('nome', $perfil->value)->value('id'));

        return $u;
    }

    public function test_o_pai_ve_as_faltas_do_filho_e_nao_as_de_outro_aluno(): void
    {
        $this->aulaComRegistos($this->c, '2026-03-02', [EstadoPresenca::FALTA, EstadoPresenca::FALTA]);
        $filho = $this->conta(Perfil::ALUNO, 'filho@example.com', $this->c['alunos'][0]->aluno->dados_pessoa_id);
        $outro = $this->conta(Perfil::ALUNO, 'outro@example.com', $this->c['alunos'][1]->aluno->dados_pessoa_id);
        $pai = $this->conta(Perfil::ENCARREGADO, 'pai@example.com');
        $pai->educandos()->attach($filho->id, ['parentesco' => 'Pai']);

        $this->actingAs($pai)->get("/portal/{$filho->id}/faltas")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Frequencia/MinhasFaltas')
            ->where('educando.id', $filho->id)
            ->has('blocos', 1)
            ->where('blocos.0.resumo.total', 1));

        $this->actingAs($pai)->get("/portal/{$outro->id}/faltas")->assertNotFound();
    }
}
