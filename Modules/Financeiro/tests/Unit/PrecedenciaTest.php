<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Support\Precedencia;
use PHPUnit\Framework\TestCase;

class PrecedenciaTest extends TestCase
{
    /**
     * Os nove níveis, do menos para o mais específico.
     *
     * @return array<string, array<string, int>>
     */
    private function niveis(): array
    {
        return [
            'Geral' => [],
            'Turno' => ['turno_id' => 1],
            'Nível' => ['nivel_academico_id' => 1],
            'Curso' => ['curso_id' => 1],
            'Nível + Turno' => ['nivel_academico_id' => 1, 'turno_id' => 1],
            'Curso + Turno' => ['curso_id' => 1, 'turno_id' => 1],
            'Curso + Nível' => ['curso_id' => 1, 'nivel_academico_id' => 1],
            'Curso + Nível + Turno' => ['curso_id' => 1, 'nivel_academico_id' => 1, 'turno_id' => 1],
            'Turma específica' => ['turma_id' => 1],
        ];
    }

    public function test_a_ordem_dos_nove_niveis_e_estritamente_crescente(): void
    {
        $alvos = array_values($this->niveis());

        for ($i = 0; $i < count($alvos) - 1; $i++) {
            $this->assertSame(-1, Precedencia::rank($alvos[$i]) <=> Precedencia::rank($alvos[$i + 1]), "nível {$i} deve perder para o seguinte");
        }
    }

    public function test_curso_vence_nivel_e_nivel_mais_turno_vence_so_curso(): void
    {
        $this->assertSame(1, Precedencia::rank(['curso_id' => 1]) <=> Precedencia::rank(['nivel_academico_id' => 1]));
        $this->assertSame(1, Precedencia::rank(['nivel_academico_id' => 1, 'turno_id' => 1]) <=> Precedencia::rank(['curso_id' => 1]));
    }

    public function test_so_conta_se_o_valor_esta_definido(): void
    {
        $this->assertSame(
            Precedencia::rank([]),
            Precedencia::rank(['nivel_academico_id' => null, 'curso_id' => null, 'turno_id' => null, 'turma_id' => null]),
        );
        $this->assertSame(Precedencia::rank(['curso_id' => 7]), Precedencia::rank(['curso_id' => 99, 'turno_id' => null]));
    }

    public function test_descricoes(): void
    {
        foreach ($this->niveis() as $descricao => $alvo) {
            $this->assertSame($descricao, Precedencia::descricao($alvo));
        }
    }
}
