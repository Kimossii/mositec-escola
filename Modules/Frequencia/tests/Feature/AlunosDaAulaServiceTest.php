<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Services\AlunosDaAulaService;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\MatriculaHistorico;
use Tests\TestCase;

class AlunosDaAulaServiceTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private function lista(array $c, string $data, TipoAula $tipo, ?int $disciplinaId): array
    {
        return app(AlunosDaAulaService::class)->daAula($c['turma']->id, Carbon::parse($data), $tipo, $disciplinaId)
            ->map(fn ($m) => $m->aluno->dadosPessoa->nome_completo)
            ->all();
    }

    public function test_so_matriculas_activas_com_data_valida_ordenadas_por_nome(): void
    {
        $c = $this->cenarioDeFrequencia();
        $c['alunos'][1]->update(['data_fim' => '2026-02-28']); // saiu antes
        $c['alunos'][2]->update(['data_matricula' => '2026-03-10']); // chega depois

        $this->assertSame(['Ana Aluna'], $this->lista($c, '2026-03-02', TipoAula::TEMPO, $c['disciplina']->id));
        $this->assertSame(['Ana Aluna', 'Carla Aluna'], $this->lista($c, '2026-03-16', TipoAula::TEMPO, $c['disciplina']->id));
    }

    public function test_trancada_pendente_e_cancelada_ficam_de_fora(): void
    {
        $c = $this->cenarioDeFrequencia();
        $c['alunos'][0]->update(['estado' => EstadoMatriculaEnum::TRANCADA->value]);
        $c['alunos'][1]->update(['estado' => EstadoMatriculaEnum::PENDENTE->value]);
        $c['alunos'][2]->update(['estado' => EstadoMatriculaEnum::CANCELADA->value]);

        $this->assertSame([], $this->lista($c, '2026-03-02', TipoAula::TEMPO, $c['disciplina']->id));
        $this->assertSame([], $this->lista($c, '2026-03-02', TipoAula::DIA, null));
    }

    public function test_regime_tempo_exige_inscricao_na_disciplina(): void
    {
        $c = $this->cenarioDeFrequencia();
        $so = $this->matricularAluno($c, 'Dora Só Português', '2026-02-01', $c['outraDisciplina']->id);
        InscricaoDisciplina::query()->where('matricula_id', $c['alunos'][0]->id)->update(['estado' => EstadoInscricaoDisciplinaEnum::DESISTIDA->value]);

        $matematica = $this->lista($c, '2026-03-02', TipoAula::TEMPO, $c['disciplina']->id);
        $portugues = $this->lista($c, '2026-03-02', TipoAula::TEMPO, $c['outraDisciplina']->id);

        $this->assertSame(['Bruno Aluno', 'Carla Aluna'], $matematica); // a Ana desistiu da disciplina
        $this->assertSame(['Dora Só Português'], $portugues);
        $this->assertSame($so->id, app(AlunosDaAulaService::class)->daAula($c['turma']->id, Carbon::parse('2026-03-02'), TipoAula::TEMPO, $c['outraDisciplina']->id)->first()->id);
    }

    public function test_regime_dia_ignora_disciplina(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->matricularAluno($c, 'Dora Só Português', '2026-02-01', $c['outraDisciplina']->id);

        $this->assertSame(['Ana Aluna', 'Bruno Aluno', 'Carla Aluna', 'Dora Só Português'], $this->lista($c, '2026-03-02', TipoAula::DIA, null));
    }

    private function mudarEstado($matricula, EstadoMatriculaEnum $de, EstadoMatriculaEnum $para, string $efeito): void
    {
        $matricula->update(['estado' => $para->value]);
        MatriculaHistorico::create(['matricula_id' => $matricula->id, 'estado_anterior' => $de, 'estado_novo' => $para, 'data_efeito' => $efeito]);
    }

    public function test_aluno_transferido_depois_da_aula_ainda_entra_na_chamada_dessa_data(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->mudarEstado($c['alunos'][0], EstadoMatriculaEnum::ACTIVA, EstadoMatriculaEnum::TRANSFERIDA, '2026-03-10');

        $this->assertContains('Ana Aluna', $this->lista($c, '2026-03-09', TipoAula::TEMPO, $c['disciplina']->id));
        $this->assertNotContains('Ana Aluna', $this->lista($c, '2026-03-11', TipoAula::TEMPO, $c['disciplina']->id));
    }

    public function test_aluno_trancado_depois_da_aula_entra_e_depois_de_reactivado_volta(): void
    {
        $c = $this->cenarioDeFrequencia();
        $ana = $c['alunos'][0];
        $this->mudarEstado($ana, EstadoMatriculaEnum::ACTIVA, EstadoMatriculaEnum::TRANCADA, '2026-03-10');
        $this->mudarEstado($ana, EstadoMatriculaEnum::TRANCADA, EstadoMatriculaEnum::ACTIVA, '2026-03-20');

        $nomes = fn (string $data) => $this->lista($c, $data, TipoAula::TEMPO, $c['disciplina']->id);

        $this->assertContains('Ana Aluna', $nomes('2026-03-09'));
        $this->assertNotContains('Ana Aluna', $nomes('2026-03-12'));
        $this->assertContains('Ana Aluna', $nomes('2026-03-23'));
    }

    public function test_matricula_activada_depois_da_data_nao_entra(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->mudarEstado($c['alunos'][1], EstadoMatriculaEnum::PENDENTE, EstadoMatriculaEnum::ACTIVA, '2026-03-05');

        $this->assertNotContains('Bruno Aluno', $this->lista($c, '2026-03-02', TipoAula::TEMPO, $c['disciplina']->id));
        $this->assertContains('Bruno Aluno', $this->lista($c, '2026-03-09', TipoAula::TEMPO, $c['disciplina']->id));
    }
}
