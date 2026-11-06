<?php

namespace Modules\Avaliacao\Tests\Unit;

use Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa;
use Modules\Avaliacao\Support\Calculo\RegraDoRecurso;
use PHPUnit\Framework\TestCase;

class RegraDoRecursoTest extends TestCase
{
    private function avaliar(array $notas, ?int $maximo = null, array $chave = []): array
    {
        return (new RegraDoRecurso)->avaliar($notas, '5', $maximo, $chave, new CalculadoraQuantitativa);
    }

    public function test_sem_negativas_nao_ha_recurso(): void
    {
        $a = $this->avaliar([1 => '7.00', 2 => '5.00']);

        $this->assertSame([], $a['reprovadas']);
        $this->assertFalse($a['elegivel']);
        $this->assertNull($a['motivo']);
    }

    public function test_com_negativas_e_sem_regras_vai_ao_recurso_e_o_minimo_exacto_passa(): void
    {
        $a = $this->avaliar([1 => '4.99', 2 => '5.00', 3 => '2.00', 4 => null]);

        $this->assertSame([1, 3], $a['reprovadas']);
        $this->assertTrue($a['elegivel']);
    }

    public function test_acima_do_maximo_e_reprovacao_directa_e_no_maximo_ainda_vai(): void
    {
        $this->assertFalse($this->avaliar([1 => '1', 2 => '2', 3 => '3'], 2)['elegivel']);
        $this->assertSame(RegraDoRecurso::MOTIVO_MAXIMO, $this->avaliar([1 => '1', 2 => '2', 3 => '3'], 2)['motivo']);
        $this->assertTrue($this->avaliar([1 => '1', 2 => '2', 3 => '9'], 2)['elegivel']);
    }

    public function test_negativa_a_disciplina_chave_tira_o_direito_mesmo_com_poucas_negativas(): void
    {
        $a = $this->avaliar([1 => '9', 2 => '3'], 5, [2]);

        $this->assertFalse($a['elegivel']);
        $this->assertSame(RegraDoRecurso::MOTIVO_CHAVE, $a['motivo']);
        $this->assertSame([2], $a['chave']);
        $this->assertTrue($this->avaliar([1 => '3', 2 => '9'], 5, [2])['elegivel']); // a negativa não é na chave
    }

    public function test_as_frases_citam_os_nomes_e_os_numeros(): void
    {
        $a = $this->avaliar([1 => '3', 2 => '9'], null, [1]);

        $this->assertSame('Negativa a disciplina-chave (Matemática): reprovação directa, sem recurso.', RegraDoRecurso::descrever($a, null, [1 => 'Matemática']));
        $this->assertNull(RegraDoRecurso::descrever($this->avaliar([1 => '3']), null, []));
    }
}
