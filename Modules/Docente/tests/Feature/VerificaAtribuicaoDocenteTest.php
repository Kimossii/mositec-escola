<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class VerificaAtribuicaoDocenteTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private function contrato(): VerificaAtribuicaoDocente
    {
        return app(VerificaAtribuicaoDocente::class);
    }

    /** Utilizador com conta ligada a um docente: o contrato recebe o id do UTILIZADOR. */
    private function utilizadorDocente(string $nome = 'Docente Teste'): User
    {
        return $this->criarDocente($nome, comAcesso: true)->utilizador();
    }

    private function atribuir(array $cenario, User $utilizador, array $atributos = []): AtribuicaoDocente
    {
        return AtribuicaoDocente::create(array_merge([
            'ano_lectivo_id' => $cenario['ano']->id,
            'turma_id' => $cenario['turma']->id,
            'disciplina_id' => $cenario['disciplina']->id,
            'docente_id' => Docente::query()->where('dados_pessoa_id', $utilizador->dados_pessoa_id)->value('id'),
            'papel' => PapelDocente::TITULAR,
            'data_inicio' => '2026-03-01',
            'data_fim' => null,
        ], $atributos));
    }

    private function pode(array $c, int $professorId, string $data, ?int $disciplinaId = null): bool
    {
        return $this->contrato()->podeGerir($professorId, $c['turma']->id, $disciplinaId ?? $c['disciplina']->id, Carbon::parse($data));
    }

    public function test_titular_pode_gerir_na_sua_disciplina_e_turma(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->utilizadorDocente();
        $this->atribuir($c, $prof);

        $this->assertTrue($this->pode($c, $prof->id, '2026-06-15'));
        $this->assertFalse($this->pode($c, $prof->id, '2026-06-15', $c['outraDisciplina']->id));
    }

    public function test_sem_data_usa_hoje_no_fuso_do_tenant(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->utilizadorDocente();
        $this->atribuir($c, $prof, ['data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31']);

        Carbon::setTestNow('2026-03-15 12:00:00');
        $this->assertTrue($this->contrato()->podeGerir($prof->id, $c['turma']->id, $c['disciplina']->id));

        Carbon::setTestNow('2026-04-10 12:00:00');
        $this->assertFalse($this->contrato()->podeGerir($prof->id, $c['turma']->id, $c['disciplina']->id));
        Carbon::setTestNow();
    }

    public function test_vigencia_efectiva_e_cortada_pelo_fim_do_ano_lectivo(): void
    {
        $c = $this->cenarioDeDocencia();
        $semFim = $this->utilizadorDocente('Docente a');
        $comFimDepoisDoAno = $this->utilizadorDocente('Docente b');
        $this->atribuir($c, $semFim, ['data_fim' => null]);
        $this->atribuir($c, $comFimDepoisDoAno, ['disciplina_id' => $c['outraDisciplina']->id, 'data_fim' => '2027-06-30']);

        $this->assertTrue($this->pode($c, $semFim->id, '2026-12-31'));
        $this->assertFalse($this->pode($c, $semFim->id, '2027-01-01'));
        $this->assertTrue($this->pode($c, $comFimDepoisDoAno->id, '2026-12-31', $c['outraDisciplina']->id));
        $this->assertFalse($this->pode($c, $comFimDepoisDoAno->id, '2027-03-01', $c['outraDisciplina']->id));
    }

    public function test_substituto_pode_gerir(): void
    {
        $c = $this->cenarioDeDocencia();
        $titular = $this->utilizadorDocente('Docente t');
        $substituto = $this->utilizadorDocente('Docente s');
        $this->atribuir($c, $titular, ['data_fim' => '2026-04-30']);
        $this->atribuir($c, $substituto, ['papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-03-10', 'data_fim' => '2026-03-20']);

        $this->assertTrue($this->pode($c, $substituto->id, '2026-03-15'));
        $this->assertFalse($this->pode($c, $substituto->id, '2026-03-21'));
    }

    public function test_outro_professor_nao_pode(): void
    {
        $c = $this->cenarioDeDocencia();
        $titular = $this->utilizadorDocente('Docente t');
        $outro = $this->utilizadorDocente('Docente o');
        $this->atribuir($c, $titular);

        $this->assertFalse($this->pode($c, $outro->id, '2026-06-15'));
    }

    public function test_agendada_e_terminada_nao_podem(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->utilizadorDocente();
        $this->atribuir($c, $prof, ['data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31']);

        $this->assertFalse($this->pode($c, $prof->id, '2026-02-28'));
        $this->assertTrue($this->pode($c, $prof->id, '2026-03-01'));
        $this->assertTrue($this->pode($c, $prof->id, '2026-03-31'));
        $this->assertFalse($this->pode($c, $prof->id, '2026-04-01'));
    }

    public function test_atribuicao_eliminada_nao_conta(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->utilizadorDocente();
        $this->atribuir($c, $prof, ['data_inicio' => '2026-09-01'])->delete();

        $this->assertFalse($this->pode($c, $prof->id, '2026-09-15'));
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($prof->id));
    }

    public function test_turma_eliminada_nao_conta(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->utilizadorDocente();
        $this->atribuir($c, $prof, ['data_inicio' => '2026-03-01']);
        $c['turma']->delete();

        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->assertFalse($this->pode($c, $prof->id, '2026-06-15'));
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($prof->id));
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($prof->id, $c['ano']->id));
        Carbon::setTestNow();
    }

    public function test_no_ultimo_dia_do_antigo_e_no_primeiro_do_novo_cada_um_pode_e_o_outro_nao(): void
    {
        $c = $this->cenarioDeDocencia();
        $antigo = $this->utilizadorDocente('Docente antigo');
        $novo = $this->utilizadorDocente('Docente novo');
        $this->atribuir($c, $antigo, ['data_inicio' => '2026-01-01', 'data_fim' => '2026-05-31']);
        $this->atribuir($c, $novo, ['data_inicio' => '2026-06-01', 'data_fim' => null]);

        $this->assertTrue($this->pode($c, $antigo->id, '2026-05-31'));
        $this->assertFalse($this->pode($c, $novo->id, '2026-05-31'));
        $this->assertFalse($this->pode($c, $antigo->id, '2026-06-01'));
        $this->assertTrue($this->pode($c, $novo->id, '2026-06-01'));
    }

    public function test_contrato_nao_cruza_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$cB, $profB] = $this->noTenant($outro, function () {
            $cenario = $this->cenarioDeDocencia();
            $prof = $this->utilizadorDocente('Docente b');
            $this->atribuir($cenario, $prof);

            return [$cenario, $prof];
        });

        $this->assertFalse($this->contrato()->podeGerir($profB->id, $cB['turma']->id, $cB['disciplina']->id, Carbon::parse('2026-06-15')));
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($profB->id));
        $this->assertTrue($this->noTenant($outro, fn () => $this->contrato()->podeGerir($profB->id, $cB['turma']->id, $cB['disciplina']->id, Carbon::parse('2026-06-15'))));
    }

    public function test_contrato_resolve_utilizador_para_docente(): void
    {
        $c = $this->cenarioDeDocencia();
        // Uma conta antes, para que o id do utilizador e o do docente não coincidam.
        $this->criarProfessor('antes@example.com');
        $docente = $this->criarDocente('Maria Docente', comAcesso: true);
        $utilizador = $docente->utilizador();
        $atribuicao = AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01',
        ]);

        // Ids diferentes de propósito: o contrato recebe o utilizador, nunca o docente.
        $this->assertNotSame($docente->id, $utilizador->id);
        $this->assertTrue($this->pode($c, $utilizador->id, '2026-06-15'));

        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->assertSame([$atribuicao->id], array_column($this->contrato()->atribuicoesEmVigorDoProfessor($utilizador->id), 'atribuicao_id'));
        Carbon::setTestNow();
    }

    public function test_contrato_utilizador_sem_docente_devolve_false_e_lista_vazia(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->atribuir($c, $this->utilizadorDocente());
        // Professor só com conta (sem docente ligado) e conta sem pessoa nenhuma.
        $semDocente = $this->criarProfessor('sem-docente@example.com');

        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->assertFalse($this->contrato()->podeGerir($semDocente->id, $c['turma']->id, $c['disciplina']->id));
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($semDocente->id));
        $this->assertFalse($this->contrato()->podeGerir(999999, $c['turma']->id, $c['disciplina']->id));
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor(999999));
        Carbon::setTestNow();
    }

    public function test_contrato_nao_resolve_pessoa_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$cB, $docenteB] = $this->noTenant($outro, function () {
            $cenario = $this->cenarioDeDocencia();
            $docente = $this->criarDocente('Docente B');
            AtribuicaoDocente::create([
                'ano_lectivo_id' => $cenario['ano']->id, 'turma_id' => $cenario['turma']->id, 'disciplina_id' => $cenario['disciplina']->id,
                'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01',
            ]);

            return [$cenario, $docente];
        });
        // Um utilizador do tenant A a apontar (indevidamente) para a pessoa do docente de B.
        $intruso = User::create(['name' => 'Intruso', 'email' => 'intruso@example.com', 'password' => 'segredo123', 'dados_pessoa_id' => $docenteB->dados_pessoa_id]);

        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->assertFalse($this->contrato()->podeGerir($intruso->id, $cB['turma']->id, $cB['disciplina']->id));
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($intruso->id));
        Carbon::setTestNow();
    }

    public function test_lista_em_vigor_filtra_por_ano_lectivo(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->utilizadorDocente();
        $titular = $this->atribuir($c, $prof, ['data_inicio' => '2026-03-01', 'data_fim' => '2026-04-30']);
        $this->atribuir($c, $prof, ['disciplina_id' => $c['outraDisciplina']->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-03-01', 'data_fim' => '2026-03-10']);

        Carbon::setTestNow('2026-03-05 10:00:00');
        $lista = $this->contrato()->atribuicoesEmVigorDoProfessor($prof->id, $c['ano']->id);
        Carbon::setTestNow();

        $this->assertCount(2, $lista);
        $primeira = collect($lista)->firstWhere('atribuicao_id', $titular->id);
        $this->assertSame([
            'atribuicao_id' => $titular->id,
            'turma_id' => $c['turma']->id,
            'turma_nome' => 'Turma 10A',
            'turma_rotulo' => 'Turma 10A · 2026',
            'disciplina_id' => $c['disciplina']->id,
            'disciplina_nome' => 'Matemática',
            'papel' => PapelDocente::TITULAR->value,
            'data_inicio' => '2026-03-01',
            'data_fim' => '2026-04-30',
        ], $primeira);

        Carbon::setTestNow('2026-03-05 10:00:00');
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($prof->id, $c['ano']->id + 999));
        Carbon::setTestNow('2026-03-15 10:00:00');
        $this->assertCount(1, $this->contrato()->atribuicoesEmVigorDoProfessor($prof->id));
        Carbon::setTestNow('2027-02-01 10:00:00');
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($prof->id));
        Carbon::setTestNow();
    }

    public function test_lista_em_vigor_corta_pelo_fim_do_ano_lectivo(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->utilizadorDocente();
        $this->atribuir($c, $prof, ['data_inicio' => '2026-03-01', 'data_fim' => null]);

        Carbon::setTestNow('2027-01-05 10:00:00');
        $this->assertSame([], $this->contrato()->atribuicoesEmVigorDoProfessor($prof->id));
        Carbon::setTestNow();
    }

    public function test_turma_tem_atribuicoes_so_quando_existe_alguma(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->utilizadorDocente();

        $this->assertFalse($this->contrato()->turmaTemAtribuicoes($c['turma']->id));

        $this->atribuir($c, $prof);

        $this->assertTrue($this->contrato()->turmaTemAtribuicoes($c['turma']->id));
    }

    public function test_eliminar_turma_com_atribuicoes_e_recusado(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->atribuir($c, $this->utilizadorDocente());

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        try {
            app(\Modules\Turma\Actions\EliminarTurmaAction::class)->executar($c['turma']);
        } finally {
            $this->assertNotSoftDeleted('turmas', ['id' => $c['turma']->id]);
        }
    }
}
