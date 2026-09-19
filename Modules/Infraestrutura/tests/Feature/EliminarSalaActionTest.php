<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Actions\EliminarSalaAction;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

class EliminarSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_elimina_sala_com_soft_delete(): void
    {
        $sala = Sala::create([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        (new EliminarSalaAction())->executar($sala);

        $this->assertSoftDeleted('salas', ['id' => $sala->id]);
        $this->assertSame(0, Sala::count());
        $this->assertSame(1, Sala::withTrashed()->count());
    }

    public function test_nao_elimina_sala_associada_a_uma_turma(): void
    {
        $sala = Sala::create([
            'codigo' => 'A102',
            'nome' => 'Sala 102',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N1', 'nome' => 'Nível Teste', 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma Teste']);
        $turma->turmaSalas()->create(['sala_id' => $sala->id, 'inicio' => '2026-09-01']);

        $this->expectException(ValidationException::class);

        (new EliminarSalaAction())->executar($sala);
    }
}
