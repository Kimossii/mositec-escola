<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Services\NivelAcademicoConsultaService;
use Tests\TestCase;

class NivelAcademicoConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_etapas_ensino_devolve_etapas_distintas_dos_niveis_do_estabelecimento(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Mista', 'tipo' => 1, 'is_active' => true]);

        NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CR', 'nome' => 'Creche', 'ordem' => 1, 'etapa_ensino' => EtapaEnsinoEnum::CRECHE]);
        NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 2, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '2C', 'nome' => '2ª Classe', 'ordem' => 3, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);

        $etapas = (new NivelAcademicoConsultaService())->etapasEnsino($estabelecimento->id);

        $this->assertCount(2, $etapas);
        $this->assertTrue($etapas->contains(EtapaEnsinoEnum::CRECHE));
        $this->assertTrue($etapas->contains(EtapaEnsinoEnum::PRIMARIO));
    }

    public function test_etapas_ensino_nao_mistura_estabelecimentos_diferentes(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'is_active' => false]);

        NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        NivelAcademico::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'SU', 'nome' => 'Licenciatura', 'ordem' => 1, 'etapa_ensino' => EtapaEnsinoEnum::SUPERIOR]);

        $etapas = (new NivelAcademicoConsultaService())->etapasEnsino($estabelecimentoA->id);

        $this->assertCount(1, $etapas);
        $this->assertTrue($etapas->contains(EtapaEnsinoEnum::PRIMARIO));
    }
}
