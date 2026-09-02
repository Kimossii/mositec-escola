<?php

namespace Modules\Core\Tests\Feature\Horario;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Actions\Horario\AtualizarHorarioAction;
use Modules\Core\Actions\Horario\CriarHorarioAction;
use Modules\Core\Actions\Horario\EliminarHorarioAction;
use Modules\Core\DTO\HorarioDTO;
use Modules\Core\Enums\Estado;
use Modules\Core\Models\Horario;
use Tests\TestCase;

class HorarioActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_horario_action(): void
    {
        $horario = (new CriarHorarioAction())->criar(new HorarioDTO(
            nome: 'Manhã',
            horaInicio: '08:00',
            horaFim: '12:00',
        ));

        $this->assertDatabaseHas('horarios', ['id' => $horario->id, 'nome' => 'Manhã']);
        $this->assertSame(Estado::ATIVO->value, $horario->estado);
    }

    public function test_atualizar_horario_action(): void
    {
        $horario = Horario::create([
            'nome' => 'Manhã',
            'hora_inicio' => '08:00',
            'hora_fim' => '12:00',
        ]);

        $atualizado = (new AtualizarHorarioAction())->atualizar($horario, new HorarioDTO(
            nome: 'Manhã (revisto)',
            horaInicio: '07:30',
            horaFim: '12:30',
            estado: Estado::INATIVO,
        ));

        $this->assertSame('Manhã (revisto)', $atualizado->nome);
        $this->assertSame(Estado::INATIVO->value, $atualizado->estado);
    }

    public function test_eliminar_horario_action(): void
    {
        $horario = Horario::create([
            'nome' => 'Manhã',
            'hora_inicio' => '08:00',
            'hora_fim' => '12:00',
        ]);

        (new EliminarHorarioAction())->executar($horario);

        $this->assertDatabaseMissing('horarios', ['id' => $horario->id]);
    }
}
