<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Services\AutorizacaoDeChamadaService;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class AutorizacaoDeChamadaTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private function pode($utilizador, array $c, TipoAula $tipo, ?int $disciplinaId, string $data = '2026-03-02'): bool
    {
        return app(AutorizacaoDeChamadaService::class)->podeMarcar($utilizador, $c['turma']->id, $disciplinaId, $tipo, Carbon::parse($data));
    }

    public function test_titular_marca_na_sua_disciplina_e_nao_noutra(): void
    {
        $c = $this->cenarioDeFrequencia();
        $outro = $this->criarDocente('Só Português', comAcesso: true);
        AtribuicaoDocente::query()->where('disciplina_id', $c['outraDisciplina']->id)->delete();
        $this->atribuirTitular($c, $outro, $c['outraDisciplina']->id);
        $u = $outro->utilizador();

        $this->assertTrue($this->pode($u, $c, TipoAula::TEMPO, $c['outraDisciplina']->id));
        $this->assertFalse($this->pode($u, $c, TipoAula::TEMPO, $c['disciplina']->id));
    }

    public function test_regime_dia_basta_leccionar_na_turma(): void
    {
        $c = $this->cenarioDeFrequencia();
        $outro = $this->criarDocente('Só Português', comAcesso: true);
        $this->atribuirTitular($c, $outro, $c['outraDisciplina']->id);

        $this->assertTrue($this->pode($outro->utilizador(), $c, TipoAula::DIA, null));
    }

    public function test_substituto_marca(): void
    {
        $c = $this->cenarioDeFrequencia();
        $subst = $this->criarDocente('Substituto', comAcesso: true);
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $subst->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-01-01',
        ]);

        $this->assertTrue($this->pode($subst->utilizador(), $c, TipoAula::TEMPO, $c['disciplina']->id));
    }

    public function test_professor_sem_atribuicao_ou_so_noutra_data_nao_marca(): void
    {
        $c = $this->cenarioDeFrequencia();
        $sem = $this->criarDocente('Sem Atribuição', comAcesso: true);
        $futuro = $this->criarDocente('Futuro', comAcesso: true);
        $this->atribuirTitular($c, $futuro, $c['outraDisciplina']->id, '2026-06-01');

        $this->assertFalse($this->pode($sem->utilizador(), $c, TipoAula::TEMPO, $c['disciplina']->id));
        $this->assertFalse($this->pode($futuro->utilizador(), $c, TipoAula::DIA, null, '2026-03-02'));
        $this->assertTrue($this->pode($futuro->utilizador(), $c, TipoAula::DIA, null, '2026-06-01'));
        $this->assertFalse($this->pode($this->criarProfessor('conta@example.com'), $c, TipoAula::DIA, null));
    }

    public function test_quem_tem_corrigir_fora_do_prazo_marca_sempre(): void
    {
        $c = $this->cenarioDeFrequencia();

        $this->assertTrue($this->pode($this->funcionario(), $c, TipoAula::TEMPO, $c['disciplina']->id));
    }
}
