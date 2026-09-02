<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AtualizarEventoCalendarioAction;
use Modules\AnoLectivo\Actions\CriarEventoCalendarioAction;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtualizarEventoCalendarioActionTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
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

    public function test_atualiza_titulo_e_datas_do_evento(): void
    {
        $evento = (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Início das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-01',
            dataFim: '2026-09-01',
        ));

        $actualizado = (new AtualizarEventoCalendarioAction())->atualizar($evento, new EventoCalendarioDTO(
            titulo: 'Início oficial das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-02',
            dataFim: '2026-09-02',
        ));

        $this->assertSame('Início oficial das aulas', $actualizado->titulo);
    }

    public function test_rejeita_actualizacao_fora_do_intervalo_do_ano_lectivo(): void
    {
        $evento = (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Início das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-01',
            dataFim: '2026-09-01',
        ));

        $this->expectException(ValidationException::class);

        (new AtualizarEventoCalendarioAction())->atualizar($evento, new EventoCalendarioDTO(
            titulo: 'Início das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2027-08-01',
            dataFim: '2027-08-01',
        ));
    }

    public function test_aceita_actualizacao_no_limite_superior_do_intervalo(): void
    {
        $evento = (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Encerramento do ano',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-01',
            dataFim: '2026-09-01',
        ));

        $actualizado = (new AtualizarEventoCalendarioAction())->atualizar($evento, new EventoCalendarioDTO(
            titulo: 'Encerramento oficial do ano',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2027-07-31',
            dataFim: '2027-07-31',
        ));

        $this->assertSame('Encerramento oficial do ano', $actualizado->titulo);
        $this->assertSame('2027-07-31', $actualizado->data_fim->toDateString());
    }
}
