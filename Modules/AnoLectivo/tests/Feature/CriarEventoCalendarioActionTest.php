<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\CriarEventoCalendarioAction;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarEventoCalendarioActionTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_cria_evento_dentro_do_intervalo(): void
    {
        $evento = (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Início das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-01',
            dataFim: '2026-09-01',
        ));

        $this->assertDatabaseHas('eventos_calendario', ['id' => $evento->id, 'titulo' => 'Início das aulas']);
    }

    public function test_cria_evento_no_limite_superior_do_intervalo(): void
    {
        $evento = (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Fim do ano lectivo',
            tipo: TipoEventoCalendario::EVENTO,
            dataInicio: '2027-07-31',
            dataFim: '2027-07-31',
        ));

        $this->assertDatabaseHas('eventos_calendario', ['id' => $evento->id, 'titulo' => 'Fim do ano lectivo']);
    }

    public function test_rejeita_evento_fora_do_intervalo_do_ano_lectivo(): void
    {
        $this->expectException(ValidationException::class);

        (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Fora do intervalo',
            tipo: TipoEventoCalendario::EVENTO,
            dataInicio: '2026-08-01',
            dataFim: '2026-08-15',
        ));
    }

    public function test_aceita_evento_com_data_nao_padronizada_dentro_do_intervalo(): void
    {
        $anoCurto = AnoLectivo::create([
            'nome' => 'Ano Curto',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-10-01',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        // '2026-9-5' (Set. 5) não é zero-padded; comparado como string,
        // '2026-9-5' > '2026-10-01' é verdadeiro (bug), apesar de Set. 5
        // ser antes de Out. 1 no calendário real.
        $evento = (new CriarEventoCalendarioAction())->criar($anoCurto, new EventoCalendarioDTO(
            titulo: 'Evento único',
            tipo: TipoEventoCalendario::EVENTO,
            dataInicio: '2026-09-01',
            dataFim: '2026-9-5',
        ));

        $this->assertDatabaseHas('eventos_calendario', ['id' => $evento->id, 'titulo' => 'Evento único']);
    }

    public function test_rejeita_evento_com_data_nao_padronizada_fora_do_intervalo(): void
    {
        $anoCurto = AnoLectivo::create([
            'nome' => 'Ano Curto',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-10-01',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $this->expectException(ValidationException::class);

        (new CriarEventoCalendarioAction())->criar($anoCurto, new EventoCalendarioDTO(
            titulo: 'Fora do intervalo',
            tipo: TipoEventoCalendario::EVENTO,
            dataInicio: '2026-10-2',
            dataFim: '2026-11-3',
        ));
    }
}
