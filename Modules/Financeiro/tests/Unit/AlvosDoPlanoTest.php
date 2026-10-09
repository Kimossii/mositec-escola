<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Support\AlvosDoPlano;
use PHPUnit\Framework\TestCase;

class AlvosDoPlanoTest extends TestCase
{
    private function vazio(): array
    {
        return ['nivel_academico_id' => null, 'curso_id' => null, 'turno_id' => null, 'turma_id' => null];
    }

    public function test_sem_alvos_normaliza_para_o_alvo_vazio(): void
    {
        $this->assertSame([$this->vazio()], AlvosDoPlano::normalizar([]));
        $this->assertSame([], AlvosDoPlano::paraGravar([]));
    }

    public function test_linhas_vazias_sao_ignoradas(): void
    {
        $alvos = [['nivel_academico_id' => '', 'curso_id' => null], []];

        $this->assertSame([$this->vazio()], AlvosDoPlano::normalizar($alvos));
        $this->assertSame([], AlvosDoPlano::paraGravar($alvos));
    }

    public function test_normaliza_ids_para_inteiros_e_remove_alvos_repetidos(): void
    {
        $alvos = [
            ['nivel_academico_id' => '3', 'curso_id' => null],
            ['nivel_academico_id' => 3, 'curso_id' => ''],
            ['curso_id' => '7', 'turno_id' => '2'],
        ];

        $this->assertSame(
            [
                ['nivel_academico_id' => 3, 'curso_id' => null, 'turno_id' => null, 'turma_id' => null],
                ['nivel_academico_id' => null, 'curso_id' => 7, 'turno_id' => 2, 'turma_id' => null],
            ],
            AlvosDoPlano::paraGravar($alvos),
        );
    }

    public function test_chave_distingue_os_quatro_campos(): void
    {
        $this->assertNotSame(
            AlvosDoPlano::chave(['nivel_academico_id' => 1]),
            AlvosDoPlano::chave(['curso_id' => 1]),
        );
        $this->assertNotSame(
            AlvosDoPlano::chave(['turno_id' => 1]),
            AlvosDoPlano::chave(['turma_id' => 1]),
        );
        $this->assertSame(AlvosDoPlano::chave(['curso_id' => 1]), AlvosDoPlano::chave(['curso_id' => '1', 'turno_id' => null]));
    }

    public function test_detecta_repetidos(): void
    {
        $this->assertTrue(AlvosDoPlano::temRepetidos([
            ['nivel_academico_id' => 3, 'curso_id' => null],
            ['nivel_academico_id' => '3', 'curso_id' => null],
        ]));
        $this->assertFalse(AlvosDoPlano::temRepetidos([
            ['nivel_academico_id' => 3, 'curso_id' => null],
            ['nivel_academico_id' => 3, 'curso_id' => 7],
        ]));
        $this->assertFalse(AlvosDoPlano::temRepetidos([$this->vazio()]));
    }

    public function test_turma_e_exclusiva(): void
    {
        $this->assertTrue(AlvosDoPlano::violaExclusividadeDaTurma([['turma_id' => 5, 'curso_id' => 1]]));
        $this->assertTrue(AlvosDoPlano::violaExclusividadeDaTurma([['turma_id' => 5, 'turno_id' => 1]]));
        $this->assertFalse(AlvosDoPlano::violaExclusividadeDaTurma([['turma_id' => 5], ['curso_id' => 1, 'turno_id' => 2]]));
        $this->assertFalse(AlvosDoPlano::violaExclusividadeDaTurma([]));
    }

    public function test_ids_de_turma(): void
    {
        $this->assertSame([5, 8], AlvosDoPlano::idsDeTurma([['turma_id' => '5'], ['turma_id' => 8], ['turma_id' => 5], ['curso_id' => 1]]));
        $this->assertSame([], AlvosDoPlano::idsDeTurma([]));
    }
}
