<?php

namespace Modules\Docente\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Docente\Actions\AtribuirDocenteAction;
use Modules\Docente\Actions\AtualizarAtribuicaoAction;
use Modules\Docente\Actions\EliminarAtribuicaoAction;
use Modules\Docente\Actions\EncerrarAtribuicaoAction;
use Modules\Docente\Actions\SubstituirTitularAction;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\DTO\SubstituicaoTitularDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Tests\TestCase;

class AtribuicaoDocenteAccoesTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // «Hoje» fixo a meio do ano lectivo 2026.
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function atribuir(array $c, int $docenteId, string $inicio, ?string $fim = null, PapelDocente $papel = PapelDocente::TITULAR): AtribuicaoDocente
    {
        return app(AtribuirDocenteAction::class)->executar(new AtribuicaoDocenteDTO(
            $docenteId, $c['turma']->id, $c['disciplina']->id, $papel, $inicio, $fim,
        ));
    }

    private function substituicao(array $c, int $novoId, string $ultimoDia = '2026-06-30', string $motivo = 'Licença'): SubstituicaoTitularDTO
    {
        return new SubstituicaoTitularDTO($c['turma']->id, $c['disciplina']->id, $novoId, $ultimoDia, $motivo);
    }

    private function rejeita(callable $fn, string $chave, ?string $mensagem = null): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($chave, $e->errors());
            if ($mensagem !== null) {
                $this->assertSame($mensagem, $e->errors()[$chave][0]);
            }

            return;
        }

        $this->fail('Devia ter lançado ValidationException.');
    }

    public function test_substituir_define_data_fim_no_antigo_e_cria_novo_no_dia_seguinte(): void
    {
        $c = $this->cenarioDeDocencia();
        $antigo = $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-03-01');
        $novoProf = $this->criarDocente('Docente b');

        $novo = app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-06-30', 'Licença de maternidade'));

        $antigo->refresh();
        $this->assertSame('2026-06-30', $antigo->data_fim->toDateString());
        $this->assertSame('Licença de maternidade', $antigo->motivo_encerramento);
        $this->assertSame($novoProf->id, $novo->docente_id);
        $this->assertSame(PapelDocente::TITULAR, $novo->papel);
        $this->assertSame('2026-07-01', $novo->data_inicio->toDateString());
        $this->assertNull($novo->data_fim);
        $this->assertSame($c['turma']->id, $novo->turma_id);
        $this->assertSame($c['disciplina']->id, $novo->disciplina_id);
    }

    public function test_substituir_e_atomico_se_o_novo_professor_for_invalido(): void
    {
        $c = $this->cenarioDeDocencia();
        $antigo = $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-03-01');
        $total = AtribuicaoDocente::count();

        $this->rejeita(
            fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, 999999)),
            'docente_id',
        );

        $antigo->refresh();
        $this->assertNull($antigo->data_fim);
        $this->assertNull($antigo->motivo_encerramento);
        $this->assertSame($total, AtribuicaoDocente::count());
    }

    public function test_substituir_rejeita_ultimo_dia_anterior_ao_inicio_do_titular(): void
    {
        $c = $this->cenarioDeDocencia();
        $antigo = $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-03-01');
        $novoProf = $this->criarDocente('Docente b');

        $this->rejeita(
            fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-02-15')),
            'ultimo_dia_do_titular',
            'O último dia do titular não pode ser anterior ao início da sua atribuição (01/03/2026).',
        );

        $this->assertNull($antigo->refresh()->data_fim);
        $this->assertSame(1, AtribuicaoDocente::count());
    }

    public function test_substituir_aceita_ultimo_dia_igual_ao_inicio_do_titular(): void
    {
        $c = $this->cenarioDeDocencia();
        // Começou ontem: o último dia pode ser o próprio início (ontem) e o novo começa hoje.
        $antigo = $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-06-14');
        $novoProf = $this->criarDocente('Docente b');

        $novo = app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-06-14'));

        $this->assertSame('2026-06-14', $antigo->refresh()->data_fim->toDateString());
        $this->assertSame('2026-06-15', $novo->data_inicio->toDateString());
    }

    public function test_substituir_nao_reescreve_titular_ja_terminado(): void
    {
        $c = $this->cenarioDeDocencia();
        $antigo = $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-01-10', '2026-02-10');
        $antigo->update(['motivo_encerramento' => 'Fim original']);
        $novoProf = $this->criarDocente('Docente b');

        $this->rejeita(
            fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-01-31', 'Reescrito')),
            'ultimo_dia_do_titular',
            'O titular nessa data já terminou a atribuição: uma atribuição terminada não se altera.',
        );

        $antigo->refresh();
        $this->assertSame('2026-02-10', $antigo->data_fim->toDateString());
        $this->assertSame('Fim original', $antigo->motivo_encerramento);
        $this->assertSame(1, AtribuicaoDocente::count());
    }

    public function test_substituir_nao_aceita_ultimo_dia_anterior_a_ontem(): void
    {
        $c = $this->cenarioDeDocencia();
        $antigo = $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-03-01');
        $novoProf = $this->criarDocente('Docente b');

        $this->rejeita(
            fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-06-13')),
            'ultimo_dia_do_titular',
            'O último dia do titular não pode ser anterior a 14/06/2026: o novo titular começa, no mínimo, hoje.',
        );

        $this->assertNull($antigo->refresh()->data_fim);
        $this->assertNull($antigo->motivo_encerramento);
        $this->assertSame(1, AtribuicaoDocente::count());

        // Ontem é o limite: o novo titular começa hoje.
        $novo = app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-06-14'));
        $this->assertSame('2026-06-15', $novo->data_inicio->toDateString());
    }

    public function test_substituir_rejeita_dia_seguinte_depois_do_fim_do_ano_lectivo(): void
    {
        $c = $this->cenarioDeDocencia();
        $antigo = $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-03-01');
        $novoProf = $this->criarDocente('Docente b');

        $this->rejeita(
            fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-12-31')),
            'ultimo_dia_do_titular',
            'O novo titular começaria depois do fim do ano lectivo (31/12/2026): indique um último dia anterior.',
        );

        $this->assertNull($antigo->refresh()->data_fim);
        $this->assertSame(1, AtribuicaoDocente::count());
    }

    public function test_substituir_sem_titular_em_vigor_e_rejeitado(): void
    {
        $c = $this->cenarioDeDocencia();
        $novoProf = $this->criarDocente('Docente b');

        $this->rejeita(
            fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id)),
            'ultimo_dia_do_titular',
            'Não há titular em vigor nessa data para substituir.',
        );

        // Titular já terminado antes da data indicada: também não está em vigor.
        $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-01-10', '2026-02-10');
        $this->rejeita(
            fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-06-30')),
            'ultimo_dia_do_titular',
        );
    }

    public function test_substituir_exige_motivo(): void
    {
        $c = $this->cenarioDeDocencia();
        $antigo = $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-03-01');
        $novoProf = $this->criarDocente('Docente b');

        $this->rejeita(
            fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id, '2026-06-30', '   ')),
            'motivo',
            'Indique o motivo da substituição.',
        );

        $this->assertNull($antigo->refresh()->data_fim);
    }

    public function test_substituir_nao_toca_nos_substitutos(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->atribuir($c, $this->criarDocente('Docente a')->id, '2026-03-01');
        $substituto = $this->atribuir($c, $this->criarDocente('Docente c')->id, '2026-03-01', null, PapelDocente::SUBSTITUTO);
        $novoProf = $this->criarDocente('Docente b');

        app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id));

        $this->assertNull($substituto->refresh()->data_fim);
    }

    public function test_encerrar_define_fim_e_motivo_e_aceita_data_futura(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01');

        $r = app(EncerrarAtribuicaoAction::class)->executar($a, '2026-09-30', 'Fim de contrato');

        $r->refresh();
        $this->assertSame('2026-09-30', $r->data_fim->toDateString());
        $this->assertSame('Fim de contrato', $r->motivo_encerramento);

        // Hoje também é válido.
        $b = $this->atribuir($c, $this->criarDocente('Docente b')->id, '2026-03-01', null, PapelDocente::SUBSTITUTO);
        $this->assertSame('2026-06-15', app(EncerrarAtribuicaoAction::class)->executar($b, '2026-06-15', 'Saída')->data_fim->toDateString());
    }

    public function test_encerrar_rejeita_fim_anterior_ao_inicio_e_motivo_vazio_e_fim_no_passado(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01');
        $acao = app(EncerrarAtribuicaoAction::class);

        $this->rejeita(fn () => $acao->executar($a, '2026-02-28', 'x'), 'data_fim', 'A data de fim não pode ser anterior à data de início.');
        $this->rejeita(fn () => $acao->executar($a, '2026-09-30', ' '), 'motivo', 'Indique o motivo do encerramento.');
        $this->rejeita(fn () => $acao->executar($a, '2026-06-14', 'x'), 'data_fim', 'A data de fim não pode ser anterior a hoje.');
        $this->rejeita(fn () => $acao->executar($a, '2027-01-01', 'x'), 'data_fim');

        $this->assertNull($a->refresh()->data_fim);
        $this->assertNull($a->motivo_encerramento);
    }

    public function test_encerrar_nao_alarga_a_vigencia_nem_cria_sobreposicao(): void
    {
        $c = $this->cenarioDeDocencia();
        $titular = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01', '2026-06-30');
        $this->atribuir($c, $this->criarDocente('Docente b')->id, '2026-07-01');

        $this->rejeita(
            fn () => app(EncerrarAtribuicaoAction::class)->executar($titular, '2026-07-10', 'Alargar'),
            'data_fim',
            'Encerrar só encurta a vigência; para a alargar use Editar vigência.',
        );

        $titular->refresh();
        $this->assertSame('2026-06-30', $titular->data_fim->toDateString());
        $this->assertNull($titular->motivo_encerramento);
    }

    public function test_encerrar_com_a_mesma_data_fim_ou_anterior_continua_a_funcionar(): void
    {
        $c = $this->cenarioDeDocencia();
        $titular = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01', '2026-06-30');
        $acao = app(EncerrarAtribuicaoAction::class);

        $this->assertSame('2026-06-30', $acao->executar($titular, '2026-06-30', 'Igual')->refresh()->data_fim->toDateString());
        $this->assertSame('2026-06-20', $acao->executar($titular, '2026-06-20', 'Antes')->refresh()->data_fim->toDateString());
    }

    public function test_atribuicao_terminada_nao_se_actualiza_nem_se_reabre(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->atribuir($c, $this->criarDocente()->id, '2026-01-10', '2026-02-10');

        $this->rejeita(fn () => app(AtualizarAtribuicaoAction::class)->executar($a, PapelDocente::TITULAR, '2026-01-10', null), 'atribuicao', 'Uma atribuição terminada não se altera; crie uma nova.');
        $this->rejeita(fn () => app(EncerrarAtribuicaoAction::class)->executar($a, '2026-12-31', 'Reabrir'), 'atribuicao', 'Uma atribuição terminada não se altera; crie uma nova.');

        $this->assertSame('2026-02-10', $a->refresh()->data_fim->toDateString());
    }

    public function test_actualizar_altera_papel_e_vigencia_de_agendada_e_em_curso(): void
    {
        $c = $this->cenarioDeDocencia();
        $emCurso = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01');
        $agendada = $this->atribuir($c, $this->criarDocente('Docente b')->id, '2026-09-01', null, PapelDocente::SUBSTITUTO);
        $acao = app(AtualizarAtribuicaoAction::class);

        $r = $acao->executar($emCurso, PapelDocente::TITULAR, '2026-02-01', '2026-11-30');
        $this->assertSame('2026-02-01', $r->refresh()->data_inicio->toDateString());
        $this->assertSame('2026-11-30', $r->data_fim->toDateString());

        $r = $acao->executar($agendada, PapelDocente::SUBSTITUTO, '2026-10-01', '2026-12-15');
        $this->assertSame('2026-10-01', $r->refresh()->data_inicio->toDateString());
        $this->assertSame('Substituto', $r->papel_descricao);
        $this->assertSame($emCurso->docente_id !== $r->docente_id, true);
    }

    public function test_actualizar_nao_move_inicio_de_em_curso_para_o_futuro(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01');

        $this->rejeita(fn () => app(AtualizarAtribuicaoAction::class)->executar($a, PapelDocente::TITULAR, '2026-07-01', null), 'data_inicio');
        $this->assertSame('2026-03-01', $a->refresh()->data_inicio->toDateString());
    }

    public function test_actualizar_revalida_sobreposicao_ignorando_a_propria(): void
    {
        $c = $this->cenarioDeDocencia();
        $titular = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01', '2026-06-30');
        $seguinte = $this->atribuir($c, $this->criarDocente('Docente b')->id, '2026-07-01');
        $acao = app(AtualizarAtribuicaoAction::class);

        // Sem mudar nada de relevante: a própria não conta como sobreposição.
        $acao->executar($titular, PapelDocente::TITULAR, '2026-03-01', '2026-06-30');

        // Estender o fim para dentro do seguinte: titular sobreposto.
        $this->rejeita(
            fn () => $acao->executar($titular, PapelDocente::TITULAR, '2026-03-01', '2026-07-10'),
            'data_inicio',
            ValidadorAtribuicaoDocenteService::MENSAGEM_TITULAR_SOBREPOSTO,
        );

        // Passar o seguinte a substituto é possível; depois, o primeiro já pode estender-se.
        $acao->executar($seguinte, PapelDocente::SUBSTITUTO, '2026-07-01', null);
        $this->assertSame('2026-07-10', $acao->executar($titular, PapelDocente::TITULAR, '2026-03-01', '2026-07-10')->refresh()->data_fim->toDateString());
    }

    public function test_eliminar_agendada_apaga_definitivamente(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->atribuir($c, $this->criarDocente()->id, '2026-09-01');

        app(EliminarAtribuicaoAction::class)->executar($a);

        // Nunca começou, não tem valor histórico: não fica a prender o docente (FK restrict para docentes).
        $this->assertDatabaseMissing('atribuicoes_docentes', ['id' => $a->id]);
    }

    public function test_actualizar_limpa_o_motivo_quando_a_data_fim_volta_a_ficar_aberta(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01');
        app(EncerrarAtribuicaoAction::class)->executar($a, '2026-09-30', 'Fim de contrato');

        $r = app(AtualizarAtribuicaoAction::class)->executar($a, PapelDocente::TITULAR, '2026-03-01', null);

        $this->assertNull($r->refresh()->data_fim);
        $this->assertNull($r->motivo_encerramento);
    }

    public function test_eliminar_em_curso_ou_terminada_e_rejeitado(): void
    {
        $c = $this->cenarioDeDocencia();
        $emCurso = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01');
        $terminada = $this->atribuir($c, $this->criarDocente('Docente b')->id, '2026-01-10', '2026-02-10', PapelDocente::SUBSTITUTO);

        foreach ([$emCurso, $terminada] as $a) {
            $this->rejeita(fn () => app(EliminarAtribuicaoAction::class)->executar($a), 'atribuicao', 'Uma atribuição já iniciada não se elimina; encerre-a.');
            $this->assertNotSoftDeleted('atribuicoes_docentes', ['id' => $a->id]);
        }
    }

    public function test_ano_lectivo_encerrado_bloqueia_as_quatro_accoes(): void
    {
        $c = $this->cenarioDeDocencia();
        $titular = $this->atribuir($c, $this->criarDocente()->id, '2026-03-01');
        $agendada = $this->atribuir($c, $this->criarDocente('Docente b')->id, '2026-09-01', null, PapelDocente::SUBSTITUTO);
        $novoProf = $this->criarDocente('Docente c');
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        $mensagem = 'O ano lectivo da turma está encerrado e não aceita atribuições de docentes.';

        $this->rejeita(fn () => app(SubstituirTitularAction::class)->executar($this->substituicao($c, $novoProf->id)), 'turma_id', $mensagem);
        $this->rejeita(fn () => app(EncerrarAtribuicaoAction::class)->executar($titular, '2026-09-30', 'x'), 'turma_id', $mensagem);
        $this->rejeita(fn () => app(AtualizarAtribuicaoAction::class)->executar($titular, PapelDocente::TITULAR, '2026-03-01', '2026-10-01'), 'turma_id', $mensagem);
        $this->rejeita(fn () => app(EliminarAtribuicaoAction::class)->executar($agendada), 'turma_id', $mensagem);

        $this->assertNull($titular->refresh()->data_fim);
        $this->assertNotSoftDeleted('atribuicoes_docentes', ['id' => $agendada->id]);
    }
}
