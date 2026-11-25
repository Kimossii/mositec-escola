<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Services\RegimeDeChamada;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class RegimeDeChamadaTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    public function test_etapas_pre_escolar_e_primario_sao_dia_e_as_outras_tempo(): void
    {
        $c = $this->cenarioDeFrequencia();
        $regime = app(RegimeDeChamada::class);

        foreach ([
            [EtapaEnsinoEnum::PRE_ESCOLAR, TipoAula::DIA],
            [EtapaEnsinoEnum::PRIMARIO, TipoAula::DIA],
            [EtapaEnsinoEnum::SECUNDARIO, TipoAula::TEMPO],
            [EtapaEnsinoEnum::PRIMEIRO_CICLO, TipoAula::TEMPO],
            [EtapaEnsinoEnum::SUPERIOR, TipoAula::TEMPO],
        ] as [$etapa, $esperado]) {
            $c['turma']->nivelAcademico->update(['etapa_ensino' => $etapa]);

            $this->assertSame($esperado, $regime->daTurma($c['turma']->fresh()), $etapa->name);
        }
    }
}
