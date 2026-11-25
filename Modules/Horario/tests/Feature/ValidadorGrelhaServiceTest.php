<?php

namespace Modules\Horario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Horario\DTO\GrelhaEntradaDTO;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Horario\Services\ValidadorGrelhaService;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Turma\Models\Turno;
use Tests\TestCase;

class ValidadorGrelhaServiceTest extends TestCase
{
    use CenarioDeHorario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00');
    }

    private function dto(array $c, array $o = []): GrelhaEntradaDTO
    {
        return new GrelhaEntradaDTO(
            turma_id: $o['turma_id'] ?? $c['turma']->id,
            dia_semana: $o['dia_semana'] ?? 1,
            horario_id: $o['horario_id'] ?? $c['tempo1']->id,
            disciplina_id: $o['disciplina_id'] ?? $c['disciplina']->id,
            sala_id: array_key_exists('sala_id', $o) ? $o['sala_id'] : null,
            vigencia_inicio: $o['vigencia_inicio'] ?? '2026-03-02',
            vigencia_fim: array_key_exists('vigencia_fim', $o) ? $o['vigencia_fim'] : null,
        );
    }

    private function validar(GrelhaEntradaDTO $dto, ?GrelhaEntrada $ignorar = null)
    {
        return app(ValidadorGrelhaService::class)->validar($dto, $ignorar);
    }

    private function assertFalha(string $campo, callable $fn, ?string $mensagem = null): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());
            if ($mensagem !== null) {
                $this->assertSame($mensagem, $e->errors()[$campo][0]);
            }

            return;
        }

        $this->fail("Esperava erro de validação em «{$campo}».");
    }

    /** Outra turma, no mesmo turno, com a mesma disciplina atribuída ao mesmo docente (para os conflitos). */
    private function outraTurma(array $c, string $codigo = 'T10B'): array
    {
        $turma = $c['turma']->replicate(['codigo', 'nome']);
        $turma->codigo = $codigo;
        $turma->nome = 'Turma ' . $codigo;
        $turma->save();
        $c2 = ['turma' => $turma] + $c;
        $this->atribuirTitular($c2, $c['docente'], $c['disciplina']->id);

        return $c2;
    }

    public function test_aceita_entrada_valida(): void
    {
        $c = $this->cenarioDeHorario();

        $turma = $this->validar($this->dto($c, ['sala_id' => $c['sala']->id]));

        $this->assertSame($c['turma']->id, $turma->id);
    }

    public function test_turma_sem_turno(): void
    {
        $c = $this->cenarioDeHorario();
        $c['turma']->update(['turno_id' => null]);

        $this->assertFalha('turma_id', fn () => $this->validar($this->dto($c)), ValidadorGrelhaService::MENSAGEM_TURMA_SEM_TURNO);
    }

    public function test_tempo_do_tipo_periodo(): void
    {
        $c = $this->cenarioDeHorario();

        $this->assertFalha('horario_id', fn () => $this->validar($this->dto($c, ['horario_id' => $c['intervalo']->id])));
    }

    public function test_tempo_fora_do_turno(): void
    {
        $c = $this->cenarioDeHorario();
        $outroTurno = Turno::create(['estabelecimento_id' => $c['turno']->estabelecimento_id, 'nome' => 'Tarde']);
        $tempoTarde = $this->criarTempo($outroTurno, '1º tempo tarde', '13:00', '13:45', 1);

        $this->assertFalha('horario_id', fn () => $this->validar($this->dto($c, ['horario_id' => $tempoTarde->id])));
    }

    public function test_disciplina_fora_do_plano(): void
    {
        $c = $this->cenarioDeHorario();
        $fora = Disciplina::create(['estabelecimento_id' => $c['turno']->estabelecimento_id, 'codigo' => 'FIS', 'nome' => 'Física']);

        $this->assertFalha('disciplina_id', fn () => $this->validar($this->dto($c, ['disciplina_id' => $fora->id])));
    }

    public function test_sala_inactiva(): void
    {
        $c = $this->cenarioDeHorario();
        $c['sala']->update(['estado' => EstadoSala::INATIVA]);

        $this->assertFalha('sala_id', fn () => $this->validar($this->dto($c, ['sala_id' => $c['sala']->id])));
    }

    public function test_vigencia_fora_do_ano(): void
    {
        $c = $this->cenarioDeHorario();

        $this->assertFalha('vigencia_inicio', fn () => $this->validar($this->dto($c, ['vigencia_inicio' => '2025-12-31'])));
        $this->assertFalha('vigencia_fim', fn () => $this->validar($this->dto($c, ['vigencia_fim' => '2027-01-01'])));
    }

    public function test_fim_antes_do_inicio(): void
    {
        $c = $this->cenarioDeHorario();

        $this->assertFalha('vigencia_fim', fn () => $this->validar($this->dto($c, ['vigencia_inicio' => '2026-05-10', 'vigencia_fim' => '2026-05-09'])));
    }

    public function test_sem_titular(): void
    {
        $c = $this->cenarioDeHorario();
        AtribuicaoDocente::query()->where('disciplina_id', $c['disciplina']->id)->delete();

        $this->assertFalha('disciplina_id', fn () => $this->validar($this->dto($c)), ValidadorGrelhaService::MENSAGEM_SEM_TITULAR);
    }

    public function test_conflito_de_turma(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c, ['vigencia_inicio' => '2026-03-02']);

        $this->assertFalha('horario_id', fn () => $this->validar($this->dto($c, ['disciplina_id' => $c['outraDisciplina']->id])), ValidadorGrelhaService::MENSAGEM_CONFLITO_TURMA);
    }

    public function test_vigencias_encostadas_nao_conflituam(): void
    {
        $c = $this->cenarioDeHorario();
        $this->criarEntrada($c, ['vigencia_inicio' => '2026-02-01', 'vigencia_fim' => '2026-03-31']);

        $turma = $this->validar($this->dto($c, ['vigencia_inicio' => '2026-04-01']));

        $this->assertSame($c['turma']->id, $turma->id);
    }

    public function test_conflito_de_sala(): void
    {
        $c = $this->cenarioDeHorario();
        $c2 = $this->outraTurma($c);
        $this->criarEntrada($c2, ['sala_id' => $c['sala']->id]);

        $this->assertFalha('sala_id', fn () => $this->validar($this->dto($c, ['sala_id' => $c['sala']->id])), ValidadorGrelhaService::MENSAGEM_CONFLITO_SALA);
    }

    public function test_conflito_de_docente_em_duas_turmas(): void
    {
        $c = $this->cenarioDeHorario();
        $c2 = $this->outraTurma($c);
        $this->criarEntrada($c2);

        $this->assertFalha('disciplina_id', fn () => $this->validar($this->dto($c)), ValidadorGrelhaService::MENSAGEM_CONFLITO_DOCENTE);
    }

    /** Na turma A o titular de Matemática é o docente antigo até 28/02 e um novo desde 01/03; a turma B tem o antigo o ano todo. */
    private function trocaDeTitularNaTurmaA(array $c): array
    {
        AtribuicaoDocente::query()->where('disciplina_id', $c['disciplina']->id)->update(['data_fim' => '2026-02-28']);
        $this->atribuirTitular($c, $this->criarDocente('Novo Titular'), $c['disciplina']->id, '2026-03-01');
        $c2 = $this->outraTurma($c);
        $this->criarEntrada($c2, ['vigencia_inicio' => '2026-01-01']);

        return $c2;
    }

    public function test_troca_de_titular_a_meio_nao_gera_falso_conflito(): void
    {
        $c = $this->cenarioDeHorario();
        $this->trocaDeTitularNaTurmaA($c);

        $turma = $this->validar($this->dto($c, ['vigencia_inicio' => '2026-03-02']));

        $this->assertSame($c['turma']->id, $turma->id);
    }

    public function test_conflito_real_antes_da_troca_de_titular_e_detectado(): void
    {
        $c = $this->cenarioDeHorario();
        $this->trocaDeTitularNaTurmaA($c);

        $this->assertFalha('disciplina_id', fn () => $this->validar($this->dto($c, ['vigencia_inicio' => '2026-01-05'])), ValidadorGrelhaService::MENSAGEM_CONFLITO_DOCENTE);
    }

    public function test_conflito_futuro_ja_conhecido_e_detectado(): void
    {
        $c = $this->cenarioDeHorario();
        // Turma A: X até 30/04 e Z desde 01/05. Turma B: Z o ano todo, no mesmo dia e tempo.
        $z = $this->criarDocente('Z');
        AtribuicaoDocente::query()->where('disciplina_id', $c['disciplina']->id)->update(['data_fim' => '2026-04-30']);
        $this->atribuirTitular($c, $z, $c['disciplina']->id, '2026-05-01');
        $c2 = ['turma' => $this->outraTurma($c)['turma']] + $c;
        AtribuicaoDocente::query()->where('turma_id', $c2['turma']->id)->delete();
        $this->atribuirTitular($c2, $z, $c['disciplina']->id, '2026-01-01');
        $this->criarEntrada($c2, ['vigencia_inicio' => '2026-01-01']);

        $this->assertFalha('disciplina_id', fn () => $this->validar($this->dto($c, ['vigencia_inicio' => '2026-03-02'])), ValidadorGrelhaService::MENSAGEM_CONFLITO_DOCENTE);
    }

    public function test_titulares_que_nao_coincidem_no_tempo_nao_conflituam(): void
    {
        $c = $this->cenarioDeHorario();
        // Turma A: o docente X até 30/04 e Y desde 01/05. Turma B (outra disciplina): X só desde 01/05.
        AtribuicaoDocente::query()->where('disciplina_id', $c['disciplina']->id)->update(['data_fim' => '2026-04-30']);
        $this->atribuirTitular($c, $this->criarDocente('Y'), $c['disciplina']->id, '2026-05-01');
        $c2 = ['turma' => $this->outraTurma($c)['turma']] + $c;
        AtribuicaoDocente::query()->where('turma_id', $c2['turma']->id)->delete();
        $this->atribuirTitular($c2, $c['docente'], $c['outraDisciplina']->id, '2026-05-01');
        $this->criarEntrada($c2, ['disciplina_id' => $c['outraDisciplina']->id, 'vigencia_inicio' => '2026-05-01']);

        $turma = $this->validar($this->dto($c, ['vigencia_inicio' => '2026-03-02']));

        $this->assertSame($c['turma']->id, $turma->id);
    }

    public function test_ano_encerrado(): void
    {
        $c = $this->cenarioDeHorario();
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);

        $this->assertFalha('turma_id', fn () => $this->validar($this->dto($c)));
    }

    public function test_ignorar_permite_rever_a_propria_entrada(): void
    {
        $c = $this->cenarioDeHorario();
        $entrada = $this->criarEntrada($c, ['vigencia_inicio' => '2026-03-02', 'sala_id' => $c['sala']->id]);

        $turma = $this->validar($this->dto($c, ['sala_id' => $c['sala']->id, 'vigencia_inicio' => '2026-03-02']), $entrada);

        $this->assertSame($c['turma']->id, $turma->id);
    }
}
