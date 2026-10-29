<?php

namespace Modules\Turma\Tests\Unit;

use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use PHPUnit\Framework\TestCase;

class TurmaRotuloTest extends TestCase
{
    private function turma(string $nome, bool $comTurno = true, bool $comAno = true): Turma
    {
        $turma = new Turma(['nome' => $nome, 'codigo' => 'T-1']);

        if ($comTurno) {
            $turma->setRelation('turno', new Turno(['nome' => 'Manhã']));
        }
        if ($comAno) {
            $turma->setRelation('anoLectivo', new AnoLectivo(['nome' => '2026/2027']));
        }

        return $turma;
    }

    public function test_rotulo_curto_prefixa_turma(): void
    {
        $this->assertSame('Turma 10.ª A', $this->turma('10.ª A')->rotulo());
    }

    public function test_rotulo_completo_junta_turno_e_ano_lectivo(): void
    {
        $this->assertSame('Turma 10.ª A · Manhã · 2026/2027', $this->turma('10.ª A')->rotulo(true));
    }

    public function test_nome_que_ja_comeca_por_turma_nao_duplica_a_palavra(): void
    {
        $this->assertSame('Turma 10.ª A', $this->turma('Turma 10.ª A')->rotulo());
    }

    public function test_completo_omite_partes_em_falta_ou_nao_carregadas(): void
    {
        $this->assertSame('Turma 10.ª A · 2026/2027', $this->turma('10.ª A', comTurno: false)->rotulo(true));
        $this->assertSame('Turma 10.ª A · Manhã', $this->turma('10.ª A', comAno: false)->rotulo(true));
        $this->assertSame('Turma 10.ª A', $this->turma('10.ª A', false, false)->rotulo(true));

        $turma = $this->turma('10.ª A', false, false);
        $turma->setRelation('turno', null);

        $this->assertSame('Turma 10.ª A', $turma->rotulo(true));
    }

    public function test_sem_nome_usa_o_codigo(): void
    {
        $this->assertSame('Turma T-1', (new Turma(['codigo' => 'T-1']))->rotulo());
    }
}
