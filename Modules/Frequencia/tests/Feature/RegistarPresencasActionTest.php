<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Actions\MarcarNaoLeccionadaAction;
use Modules\Frequencia\Actions\RegistarPresencasAction;
use Modules\Frequencia\DTO\PresencaDTO;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Justificacao;
use Modules\Frequencia\Models\RegistoHistorico;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class RegistarPresencasActionTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private array $c;

    private $professor;

    private Aula $aula;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->c = $this->cenarioDeFrequencia();
        $this->professor = $this->utilizadorDoDocente($this->c['docente']);
        $this->aula = app(AbrirChamadaAction::class)->executar($this->professor, $this->c['turma']->id, '2026-03-02', $this->c['tempo1']->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function registar($utilizador, array $presencas, ?string $motivo = null): Aula
    {
        return app(RegistarPresencasAction::class)->executar($utilizador, $this->aula->fresh(), $presencas, $motivo);
    }

    private function erroEm(string $campo, callable $fn): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());

            return;
        }

        $this->fail("Esperava erro de validação em «{$campo}».");
    }

    public function test_altera_falta_e_atraso_e_grava_historico_so_do_que_mudou(): void
    {
        [$a, $b, $c] = $this->c['alunos'];

        $this->registar($this->professor, [
            new PresencaDTO($a->id, EstadoPresenca::FALTA->value, null),
            new PresencaDTO($b->id, EstadoPresenca::ATRASO->value, 'chegou 10 minutos depois'),
            new PresencaDTO($c->id, EstadoPresenca::PRESENTE->value, null),
        ]);

        $porMatricula = $this->aula->registos()->get()->keyBy('matricula_id');
        $this->assertSame(EstadoPresenca::FALTA, $porMatricula[$a->id]->estado);
        $this->assertSame(EstadoPresenca::ATRASO, $porMatricula[$b->id]->estado);
        $this->assertSame('chegou 10 minutos depois', $porMatricula[$b->id]->observacao);
        $this->assertSame(EstadoPresenca::PRESENTE, $porMatricula[$c->id]->estado);

        $alteracoes = RegistoHistorico::where('evento', EventoFrequencia::ALTERACAO->value)->orderBy('id')->get();
        $this->assertCount(2, $alteracoes);
        $this->assertSame(EstadoPresenca::PRESENTE, $alteracoes[0]->estado_anterior);
        $this->assertSame(EstadoPresenca::FALTA, $alteracoes[0]->estado_novo);
        $this->assertSame($this->professor->id, (int) $alteracoes[0]->alterado_por);
    }

    public function test_aluno_fora_da_lista_recusado(): void
    {
        $fora = $this->matricularAluno($this->c, 'Fora Da Lista', '2026-03-20'); // chega depois do dia da aula

        $this->erroEm('presencas', fn () => $this->registar($this->professor, [new PresencaDTO($fora->id, EstadoPresenca::FALTA->value, null)]));
    }

    public function test_estado_invalido_recusado(): void
    {
        $this->erroEm('presencas', fn () => $this->registar($this->professor, [new PresencaDTO($this->c['alunos'][0]->id, 9, null)]));
    }

    public function test_fora_da_janela_professor_recusado(): void
    {
        Carbon::setTestNow('2026-03-20 10:00:00'); // 18 dias depois; a janela por omissão é 7

        $this->erroEm('aula', fn () => $this->registar($this->professor, [new PresencaDTO($this->c['alunos'][0]->id, EstadoPresenca::FALTA->value, null)]));
        $this->assertSame(EstadoPresenca::PRESENTE, $this->aula->registos()->where('matricula_id', $this->c['alunos'][0]->id)->first()->estado);
    }

    public function test_fora_da_janela_com_permissao_exige_motivo(): void
    {
        Carbon::setTestNow('2026-03-20 10:00:00');
        $secretaria = $this->funcionario();
        $presenca = [new PresencaDTO($this->c['alunos'][0]->id, EstadoPresenca::FALTA->value, null)];

        $this->erroEm('motivo', fn () => $this->registar($secretaria, $presenca));

        $this->registar($secretaria, $presenca, 'Erro do professor, confirmado com a direcção');
        $this->assertSame('Erro do professor, confirmado com a direcção', RegistoHistorico::where('evento', EventoFrequencia::ALTERACAO->value)->value('motivo'));
    }

    public function test_dentro_da_janela_nao_exige_motivo(): void
    {
        Carbon::setTestNow('2026-03-06 10:00:00');

        $this->registar($this->professor, [new PresencaDTO($this->c['alunos'][0]->id, EstadoPresenca::FALTA->value, null)]);

        $this->assertSame(EstadoPresenca::FALTA, $this->aula->registos()->where('matricula_id', $this->c['alunos'][0]->id)->first()->estado);
    }

    public function test_aula_nao_leccionada_recusa_presencas(): void
    {
        app(MarcarNaoLeccionadaAction::class)->executar($this->professor, $this->aula->fresh(), 'Professor doente');

        $this->erroEm('aula', fn () => $this->registar($this->professor, [new PresencaDTO($this->c['alunos'][0]->id, EstadoPresenca::FALTA->value, null)]));
        $this->assertSame(EstadoAula::NAO_LECCIONADA, $this->aula->fresh()->estado);
    }

    public function test_passar_uma_falta_justificada_a_atraso_retira_a_justificacao_e_deixa_historico(): void
    {
        $aluno = $this->c['alunos'][0];
        $registo = $this->aula->registos()->where('matricula_id', $aluno->id)->first();
        $registo->update(['estado' => EstadoPresenca::FALTA]);
        Justificacao::create(['registo_id' => $registo->id, 'motivo' => 'Doença', 'justificada_em' => Carbon::now()]);

        $this->registar($this->professor, [new PresencaDTO($aluno->id, EstadoPresenca::ATRASO->value, null)]);

        $this->assertSame(0, Justificacao::count());
        $remocao = RegistoHistorico::where('evento', EventoFrequencia::REMOCAO_JUSTIFICACAO->value)->first();
        $this->assertSame($registo->id, (int) $remocao->registo_id);
        $this->assertStringContainsString('Atraso', $remocao->motivo);
    }

    public function test_voltar_a_falta_nao_traz_a_justificacao_de_volta(): void
    {
        $aluno = $this->c['alunos'][0];
        $registo = $this->aula->registos()->where('matricula_id', $aluno->id)->first();
        $registo->update(['estado' => EstadoPresenca::FALTA]);
        Justificacao::create(['registo_id' => $registo->id, 'motivo' => 'Doença', 'justificada_em' => Carbon::now()]);

        $this->registar($this->professor, [new PresencaDTO($aluno->id, EstadoPresenca::PRESENTE->value, null)]);
        $this->registar($this->professor, [new PresencaDTO($aluno->id, EstadoPresenca::FALTA->value, null)]);

        $this->assertNull($registo->fresh()->justificacao);
    }
}
