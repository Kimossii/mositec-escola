<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Actions\CriarSalaAction;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Tests\TestCase;

class CriarSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_sala_associada_ao_estabelecimento_activo_com_estado_ativa_por_defeito(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $sala = (new CriarSalaAction())->criar(new SalaDTO(
            codigo: 'A101',
            nome: 'Sala 101',
            tipo: TipoSala::SALA_AULA,
            capacidade: 30,
        ));

        $this->assertSame($estabelecimento->id, $sala->estabelecimento_id);
        $this->assertSame(EstadoSala::ATIVA, $sala->estado);
        $this->assertSame('A101', $sala->codigo);
    }

    public function test_dto_from_request_converte_enums_a_partir_de_dados_validados(): void
    {
        $request = \Mockery::mock(\Illuminate\Foundation\Http\FormRequest::class);
        $request->shouldReceive('validated')->andReturn([
            'codigo' => 'B02',
            'nome' => 'Biblioteca',
            'tipo' => (string) TipoSala::BIBLIOTECA->value,
            'capacidade' => '50',
            'estado' => (string) EstadoSala::MANUTENCAO->value,
        ]);

        $dto = SalaDTO::fromRequest($request);

        $this->assertSame('B02', $dto->codigo);
        $this->assertSame(TipoSala::BIBLIOTECA, $dto->tipo);
        $this->assertSame(50, $dto->capacidade);
        $this->assertSame(EstadoSala::MANUTENCAO, $dto->estado);
    }
}
