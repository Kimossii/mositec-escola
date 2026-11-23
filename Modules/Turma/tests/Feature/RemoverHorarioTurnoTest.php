<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Models\Horario;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Contracts\DependenciasDoTempoDoTurno;
use Modules\Turma\Models\Turno;
use Modules\Turma\Models\TurnoHorario;
use Modules\Turma\Support\DependenciasRegistadasDoTurno;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RemoverHorarioTurnoTest extends TestCase
{
    use RefreshDatabase;

    private Turno $turno;

    private TurnoHorario $ligacao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->turno = Turno::create(['estabelecimento_id' => Estabelecimento::current()->id, 'nome' => 'Manhã']);
        $this->ligacao = $this->ligar($this->turno, '1º tempo', '08:00', '08:45', 1);
    }

    private function ligar(Turno $turno, string $nome, string $inicio, string $fim, int $ordem): TurnoHorario
    {
        $horario = Horario::create(['nome' => $nome, 'hora_inicio' => $inicio, 'hora_fim' => $fim]);

        return TurnoHorario::create(['turno_id' => $turno->id, 'horario_id' => $horario->id, 'ordem' => $ordem]);
    }

    private function como(Perfil $perfil): User
    {
        $u = User::create(['name' => $perfil->value, 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);
        $this->actingAs($u);

        return $u;
    }

    public function test_remove_o_tempo_do_turno_sem_apagar_o_horario(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->delete(route('turnos.horarios.destroy', [$this->turno, $this->ligacao]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(0, TurnoHorario::count());
        $this->assertNotNull(Horario::find($this->ligacao->horario_id), 'o tempo continua no catálogo');
    }

    public function test_ligacao_de_outro_turno_da_404(): void
    {
        $outro = Turno::create(['estabelecimento_id' => Estabelecimento::current()->id, 'nome' => 'Tarde']);
        $deOutro = $this->ligar($outro, '1º tempo tarde', '13:00', '13:45', 1);
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->delete(route('turnos.horarios.destroy', [$this->turno, $deOutro]))->assertNotFound();

        $this->assertSame(2, TurnoHorario::count());
    }

    public function test_so_quem_pode_editar_turmas(): void
    {
        $this->como(Perfil::PROFESSOR);

        $this->delete(route('turnos.horarios.destroy', [$this->turno, $this->ligacao]))->assertForbidden();

        $this->assertSame(1, TurnoHorario::count());
    }

    public function test_um_modulo_com_o_tempo_em_uso_bloqueia_a_remocao(): void
    {
        $this->app->bind('teste.tempo-em-uso', fn () => new class implements DependenciasDoTempoDoTurno {
            public function bloqueiaRemocao(Turno $turno, int $horarioId): ?string
            {
                return 'Este tempo tem chamadas registadas.';
            }
        });
        $this->app->tag(['teste.tempo-em-uso'], DependenciasRegistadasDoTurno::ETIQUETA);
        $this->como(Perfil::ADMIN_ESCOLA);

        $this->delete(route('turnos.horarios.destroy', [$this->turno, $this->ligacao]))
            ->assertSessionHasErrors(['horario' => 'Este tempo tem chamadas registadas.']);

        $this->assertSame(1, TurnoHorario::count());
    }
}
