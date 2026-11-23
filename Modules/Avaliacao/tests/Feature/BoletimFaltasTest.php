<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Services\BoletimDocumentoService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Justificacao;
use Modules\Frequencia\Models\Registo;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class BoletimFaltasTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private InscricaoDisciplina $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-15 10:00:00');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
        $this->c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($this->c['ano']->id, [$this->c['nivel']->id], 10, '5'));
        $this->ana = $this->inscreverAluno($this->c, 'Ana');
        $this->notasCompletas($this->c, $this->ana, [1 => ['5', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);
    }

    private function boletim()
    {
        return app(BoletimDocumentoService::class)->paraTurma($this->c['turma']->id)[0];
    }

    /** Uma aula guardada com o estado dado à Ana; devolve o registo. */
    private function aula(string $data, EstadoPresenca $estado, TipoAula $tipo): Registo
    {
        $aula = Aula::create([
            'ano_lectivo_id' => $this->c['ano']->id, 'turma_id' => $this->c['turma']->id, 'tipo' => $tipo, 'data' => $data,
            'disciplina_id' => $tipo === TipoAula::TEMPO ? $this->c['disciplina']->id : null,
        ]);

        return Registo::create(['aula_id' => $aula->id, 'matricula_id' => $this->ana->matricula_id, 'estado' => $estado]);
    }

    public function test_sem_chamadas_o_boletim_nao_traz_faltas(): void
    {
        $b = $this->boletim();

        $this->assertSame(['Disciplina', 'MT1', 'MT2', 'MT3', 'MFD'], $b->colunas);
        $this->assertSame([], array_values(array_filter($b->identificacao, fn (array $i) => str_starts_with($i[0], 'Faltas'))));
    }

    public function test_regime_dia_traz_linha_de_faltas_na_identificacao(): void
    {
        $falta = $this->aula('2026-03-02', EstadoPresenca::FALTA, TipoAula::DIA);
        $this->aula('2026-03-03', EstadoPresenca::FALTA, TipoAula::DIA);
        Justificacao::create(['registo_id' => $falta->id, 'motivo' => 'Doença', 'justificada_em' => now()]);

        $b = $this->boletim();

        $this->assertContains(['Faltas (dias)', '2 (1 justificadas)'], $b->identificacao);
        $this->assertSame(['Disciplina', 'MT1', 'MT2', 'MT3', 'MFD'], $b->colunas);
    }

    public function test_regime_tempo_traz_coluna_de_faltas_por_disciplina(): void
    {
        $this->c['nivel']->update(['etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);
        $this->aula('2026-03-02', EstadoPresenca::FALTA, TipoAula::TEMPO);
        $this->aula('2026-03-03', EstadoPresenca::FALTA, TipoAula::TEMPO);

        $b = $this->boletim();

        $this->assertSame(['Disciplina', 'MT1', 'MT2', 'MT3', 'MFD', 'Faltas'], $b->colunas);
        $this->assertSame('2', $b->linhas[0][array_key_last($b->linhas[0])]);
        $this->assertSame([], array_values(array_filter($b->identificacao, fn (array $i) => str_starts_with($i[0], 'Faltas'))));
    }

    public function test_regime_tempo_sem_faltas_mas_com_chamadas_mostra_zero(): void
    {
        $this->c['nivel']->update(['etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);
        $this->aula('2026-03-02', EstadoPresenca::PRESENTE, TipoAula::TEMPO);

        $b = $this->boletim();

        $this->assertSame('Faltas', $b->colunas[array_key_last($b->colunas)]);
        $this->assertSame('0', $b->linhas[0][array_key_last($b->linhas[0])]);
    }
}
