<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DashboardFrequenciaTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00'); // segunda-feira: há uma aula prevista hoje
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function administrador(): User
    {
        $u = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $u->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);

        return $u;
    }

    /** O item da atenção com esse rótulo, ou null. */
    private function item(User $utilizador, string $rotulo): ?array
    {
        $item = null;
        $this->actingAs($utilizador)->get('/')->assertInertia(function (Assert $page) use (&$item, $rotulo) {
            $page->loadDeferredProps(function (Assert $c) use (&$item, $rotulo) {
                $c->where('atencao', function ($itens) use (&$item, $rotulo) {
                    $item = collect($itens)->firstWhere('rotulo', $rotulo);

                    return true;
                });
            });
        });

        return $item;
    }

    public function test_professor_ve_chamadas_por_fazer_e_direccao_aulas_sem_chamada(): void
    {
        $c = $this->cenarioDeFrequencia();
        $professor = $this->utilizadorDoDocente($c['docente']);

        $doProfessor = $this->item($professor, 'Chamadas por fazer');
        $this->assertSame(1, $doProfessor['quantidade']);
        $this->assertSame('/frequencia', $doProfessor['link']);
        $this->assertNull($this->item($professor, 'Aulas sem chamada'), 'o professor não vê as pendências da escola toda');

        $daDireccao = $this->item($this->administrador(), 'Aulas sem chamada');
        $this->assertSame(1, $daDireccao['quantidade']);
        $this->assertSame('/frequencia/pendentes', $daDireccao['link']);
    }

    public function test_sem_pendentes_nao_mostra_o_item(): void
    {
        $c = $this->cenarioDeFrequencia();
        $professor = $this->utilizadorDoDocente($c['docente']);
        $this->aulaComRegistos($c, '2026-03-02');

        $this->assertNull($this->item($professor, 'Chamadas por fazer'));
        $this->assertNull($this->item($this->administrador(), 'Aulas sem chamada'));
    }
}
