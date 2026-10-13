<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\DTO\ResultadoGeracaoMatriculaDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use PHPUnit\Framework\TestCase;

class ResultadoGeracaoPropinasDTOTest extends TestCase
{
    private function matricula(int $id, int $criadas = 0, int $conflitos = 0, int $erros = 0): ResultadoGeracaoMatriculaDTO
    {
        $matricula = new ResultadoGeracaoMatriculaDTO(matricula_id: $id, aluno_nome: "Aluno {$id}");

        for ($i = 0; $i < $criadas; $i++) {
            $matricula->aCriar(['periodo' => "P{$i}"]);
        }

        for ($i = 0; $i < $conflitos; $i++) {
            $matricula->conflito("C{$i}");
        }

        for ($i = 0; $i < $erros; $i++) {
            $matricula->erro("E{$i}");
        }

        $matricula->ignorada('Já coberto.', 'Set/2026', 'Plano');
        $matricula->semPlano('Jul/2027');

        return $matricula;
    }

    public function test_os_totais_somam_todas_as_matriculas(): void
    {
        $resultado = new ResultadoGeracaoPropinasDTO(previsualizacao: true);
        $resultado->juntar($this->matricula(1, criadas: 2));
        $resultado->juntar($this->matricula(2, conflitos: 1));
        $resultado->juntar($this->matricula(3, erros: 1));

        $this->assertSame(
            ['matriculas' => 3, 'criadas' => 2, 'ignoradas' => 3, 'conflitos' => 1, 'sem_plano' => 3, 'erros' => 1],
            $resultado->totais(),
        );
    }

    public function test_o_detalhe_mostra_erros_depois_conflitos_depois_criadas_e_corta_no_limite(): void
    {
        $resultado = new ResultadoGeracaoPropinasDTO(previsualizacao: false);
        $resultado->juntar($this->matricula(1));
        $resultado->juntar($this->matricula(2, criadas: 1));
        $resultado->juntar($this->matricula(3, conflitos: 1));
        $resultado->juntar($this->matricula(4, erros: 1));

        $array = $resultado->toArray(limite: 3);

        $this->assertSame([4, 3, 2], array_column($array['matriculas'], 'matricula_id'));
        $this->assertTrue($array['detalhe_truncado']);
        $this->assertFalse($array['previsualizacao']);
        $this->assertSame(4, $array['totais']['matriculas']);
        $this->assertFalse($resultado->toArray()['detalhe_truncado']);
        $this->assertSame(
            ['matricula_id', 'numero_registo', 'aluno_nome', 'aluno_numero', 'turma_nome', 'criadas', 'ignoradas', 'conflitos', 'sem_plano', 'erros'],
            array_keys($array['matriculas'][0]),
        );
    }

    public function test_criada_regista_o_id_na_linha_certa(): void
    {
        $matricula = new ResultadoGeracaoMatriculaDTO(matricula_id: 9);
        $matricula->aCriar(['periodo' => 'Set/2026']);
        $matricula->aCriar(['periodo' => 'Out/2026']);

        $matricula->criada(1, 77);

        $this->assertSame([['periodo' => 'Set/2026'], ['periodo' => 'Out/2026', 'id' => 77]], $matricula->criadas());
    }
}
