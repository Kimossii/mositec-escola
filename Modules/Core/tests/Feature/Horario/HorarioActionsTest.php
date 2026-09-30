<?php

namespace Modules\Core\Tests\Feature\Horario;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\Horario\AtualizarHorarioAction;
use Modules\Core\Actions\Horario\CriarHorarioAction;
use Modules\Core\Actions\Horario\EliminarHorarioAction;
use Modules\Core\DTO\HorarioDTO;
use Modules\Core\Enums\Estado;
use Modules\Core\Enums\TipoHorarioEnum;
use Modules\Core\Models\Horario;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\Turno;
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
        $this->assertSame(TipoHorarioEnum::TEMPO, $horario->tipo);
        $this->assertSame('Tempo', $horario->tipo_descricao);
    }

    public function test_criar_horario_action_com_tipo_periodo(): void
    {
        $horario = (new CriarHorarioAction())->criar(new HorarioDTO(
            nome: 'Recreio',
            horaInicio: '10:00',
            horaFim: '10:30',
            tipo: TipoHorarioEnum::PERIODO,
        ));

        $this->assertSame(TipoHorarioEnum::PERIODO, $horario->tipo);
        $this->assertSame('Período', $horario->tipo_descricao);
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
            tipo: TipoHorarioEnum::PERIODO,
        ));

        $this->assertSame('Manhã (revisto)', $atualizado->nome);
        $this->assertSame(Estado::INATIVO->value, $atualizado->estado);
        $this->assertSame(TipoHorarioEnum::PERIODO, $atualizado->tipo);
        $this->assertSame('Período', $atualizado->tipo_descricao);
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

    public function test_nao_elimina_horario_associado_a_um_turno(): void
    {
        $horario = Horario::create([
            'nome' => 'Manhã',
            'hora_inicio' => '08:00',
            'hora_fim' => '12:00',
        ]);
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'is_active' => true]);
        $turno = Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);
        $turno->turnoHorarios()->create(['horario_id' => $horario->id, 'ordem' => 1]);

        $this->expectException(ValidationException::class);

        (new EliminarHorarioAction())->executar($horario);
    }
}
