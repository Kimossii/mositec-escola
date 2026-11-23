<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Actions\JustificarFaltaAction;
use Modules\Frequencia\Actions\RemoverJustificacaoAction;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Frequencia\Models\Justificacao;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Models\RegistoHistorico;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class JustificacaoActionsTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private array $c;

    private $professor;

    private Registo $falta;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
        $this->c = $this->cenarioDeFrequencia();
        $this->professor = $this->utilizadorDoDocente($this->c['docente']);
        $aula = app(AbrirChamadaAction::class)->executar($this->professor, $this->c['turma']->id, '2026-03-02', $this->c['tempo1']->id);
        $this->falta = $aula->registos->firstWhere('matricula_id', $this->c['alunos'][0]->id);
        $this->falta->update(['estado' => EstadoPresenca::FALTA]);
        $aula->registos->firstWhere('matricula_id', $this->c['alunos'][1]->id)->update(['estado' => EstadoPresenca::ATRASO]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function justificar($u, ?Registo $registo = null, string $motivo = 'Doença, atestado médico'): Justificacao
    {
        return app(JustificarFaltaAction::class)->executar($u, $registo ?? $this->falta->fresh(), $motivo, 'Atestado n.º 123');
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

    public function test_justifica_falta_e_grava_quem_quando_e_historico(): void
    {
        $j = $this->justificar($this->professor);

        $this->assertSame($this->falta->id, (int) $j->registo_id);
        $this->assertSame('Doença, atestado médico', $j->motivo);
        $this->assertSame('Atestado n.º 123', $j->documento_referencia);
        $this->assertSame($this->professor->id, (int) $j->justificada_por);
        $this->assertSame('2026-03-02', $j->justificada_em->toDateString());
        $linha = RegistoHistorico::where('evento', EventoFrequencia::JUSTIFICACAO->value)->first();
        $this->assertSame($this->falta->id, (int) $linha->registo_id);
        $this->assertSame('Doença, atestado médico', $linha->motivo);
    }

    public function test_presente_e_atraso_nao_se_justificam(): void
    {
        $atraso = Registo::where('matricula_id', $this->c['alunos'][1]->id)->first();
        $presente = Registo::where('matricula_id', $this->c['alunos'][2]->id)->first();

        $this->erroEm('registo', fn () => $this->justificar($this->professor, $atraso));
        $this->erroEm('registo', fn () => $this->justificar($this->professor, $presente));
    }

    public function test_professor_so_justifica_as_suas_aulas(): void
    {
        $outro = $this->criarDocente('Outro Professor', comAcesso: true)->utilizador();

        $this->expectException(AuthorizationException::class);

        $this->justificar($outro);
    }

    public function test_quem_nao_tem_a_permissao_de_justificar_recusado(): void
    {
        $aluno = $this->criarProfessor('semperm@example.com');
        $aluno->roles()->sync([]);

        $this->expectException(AuthorizationException::class);

        $this->justificar($aluno);
    }

    public function test_funcionario_justifica_qualquer(): void
    {
        $j = $this->justificar($this->funcionario());

        $this->assertNotNull($j->id);
    }

    public function test_justificar_duas_vezes_recusado(): void
    {
        $this->justificar($this->professor);

        $this->erroEm('registo', fn () => $this->justificar($this->professor));
        $this->assertSame(1, Justificacao::count());
    }

    public function test_remover_deixa_historico_e_a_falta_volta_a_injustificada(): void
    {
        $this->justificar($this->professor);

        app(RemoverJustificacaoAction::class)->executar($this->professor, $this->falta->fresh(), 'Atestado era de outro aluno');

        $this->assertSame(0, Justificacao::count());
        $linhas = RegistoHistorico::where('registo_id', $this->falta->id)->orderBy('id')->pluck('evento')->all();
        $this->assertContains(EventoFrequencia::REMOCAO_JUSTIFICACAO, $linhas);
        $this->assertSame('Atestado era de outro aluno', RegistoHistorico::where('evento', EventoFrequencia::REMOCAO_JUSTIFICACAO->value)->value('motivo'));
        $this->assertSame(EstadoPresenca::FALTA, $this->falta->fresh()->estado);
    }

    public function test_remover_sem_justificacao_ou_sem_motivo_recusado(): void
    {
        $this->erroEm('registo', fn () => app(RemoverJustificacaoAction::class)->executar($this->professor, $this->falta->fresh(), 'x'));

        $this->justificar($this->professor);
        $this->erroEm('motivo', fn () => app(RemoverJustificacaoAction::class)->executar($this->professor, $this->falta->fresh(), '  '));
    }

    public function test_sem_motivo_recusado(): void
    {
        $this->erroEm('motivo', fn () => $this->justificar($this->professor, null, '   '));
    }
}
