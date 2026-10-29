<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Docente\Actions\AtribuirDocenteAction;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Tests\TestCase;

class AtribuirDocenteActionTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private function action(): AtribuirDocenteAction
    {
        return app(AtribuirDocenteAction::class);
    }

    private function dto(array $c, int $docenteId, array $o = []): AtribuicaoDocenteDTO
    {
        return new AtribuicaoDocenteDTO(
            docente_id: $docenteId,
            turma_id: $o['turma_id'] ?? $c['turma']->id,
            disciplina_id: $o['disciplina_id'] ?? $c['disciplina']->id,
            papel: $o['papel'] ?? PapelDocente::TITULAR,
            data_inicio: $o['data_inicio'] ?? '2026-03-01',
            data_fim: array_key_exists('data_fim', $o) ? $o['data_fim'] : null,
        );
    }

    /** Executa e devolve a ValidationException (falha se não houver); confirma que nada foi criado. */
    private function rejeitada(AtribuicaoDocenteDTO $dto, string $chave): ValidationException
    {
        $antes = AtribuicaoDocente::count();

        try {
            $this->action()->executar($dto);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($chave, $e->errors());
            $this->assertSame($antes, AtribuicaoDocente::count());

            return $e;
        }

        $this->fail('Devia ter lançado ValidationException.');
    }

    public function test_cria_atribuicao_titular_com_ano_lectivo_da_turma(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $autor = $this->criarProfessor('autor@example.com');
        // Outro ano do mesmo tenant: o ano gravado tem de vir da turma, nunca de outro lado.
        AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'nome' => '2027', 'data_inicio' => '2027-01-01',
            'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::PLANEADO,
        ]);
        $this->actingAs($autor);

        $atribuicao = $this->action()->executar($this->dto($c, $prof->id, ['data_fim' => '2026-06-30']));

        $atribuicao->refresh();
        $this->assertSame($c['ano']->id, $atribuicao->ano_lectivo_id);
        $this->assertSame($c['turma']->id, $atribuicao->turma_id);
        $this->assertSame($c['disciplina']->id, $atribuicao->disciplina_id);
        $this->assertSame($prof->id, $atribuicao->docente_id);
        $this->assertSame(PapelDocente::TITULAR, $atribuicao->papel);
        $this->assertSame('Titular', $atribuicao->papel_descricao);
        $this->assertSame('2026-03-01', $atribuicao->data_inicio->toDateString());
        $this->assertSame('2026-06-30', $atribuicao->data_fim->toDateString());
        $this->assertSame($autor->id, $atribuicao->criado_por);
        $this->assertSame($this->tenant->id, $atribuicao->tenant_id);
    }

    public function test_atribui_docente_sem_conta(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente('Ana Sem Conta');
        $this->assertFalse($docente->temAcesso());

        $atribuicao = $this->action()->executar($this->dto($c, $docente->id));

        $this->assertSame($docente->id, $atribuicao->fresh()->docente_id);
        $this->assertSame('Ana Sem Conta', $atribuicao->docente->dadosPessoa->nome_completo);
    }

    public function test_atribui_docente_com_conta_sem_exigir_perfil(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente('Com Conta', comAcesso: true);
        // O perfil da conta deixa de importar: quem é atribuível é o docente.
        $docente->utilizador()->roles()->sync([Role::where('nome', Perfil::FUNCIONARIO->value)->firstOrFail()->id]);

        $this->assertNotNull($this->action()->executar($this->dto($c, $docente->id))->id);
    }

    public function test_rejeita_docente_inexistente(): void
    {
        $c = $this->cenarioDeDocencia();

        $e = $this->rejeitada($this->dto($c, 999999), 'docente_id');
        $this->assertSame('O docente indicado não existe.', $e->errors()['docente_id'][0]);
    }

    public function test_rejeita_docente_inactivo(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente();
        $docente->update(['estado' => 0]);

        $e = $this->rejeitada($this->dto($c, $docente->id), 'docente_id');
        $this->assertSame('O docente indicado está inactivo.', $e->errors()['docente_id'][0]);
    }

    public function test_rejeita_docente_de_outro_tenant(): void
    {
        $c = $this->cenarioDeDocencia();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $docenteB = $this->noTenant($outro, fn () => $this->criarDocente('Docente b'));

        $this->rejeitada($this->dto($c, $docenteB->id), 'docente_id');
    }

    public function test_rejeita_segundo_titular_com_vigencia_sobreposta_com_mensagem_a_indicar_substituir(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarDocente('Docente a');
        $b = $this->criarDocente('Docente b');
        $this->action()->executar($this->dto($c, $a->id, ['data_fim' => '2026-06-30']));

        $e = $this->rejeitada($this->dto($c, $b->id, ['data_inicio' => '2026-06-30']), 'data_inicio');

        $this->assertSame(ValidadorAtribuicaoDocenteService::MENSAGEM_TITULAR_SOBREPOSTO, $e->errors()['data_inicio'][0]);
        $this->assertStringContainsString('Substituir titular', $e->errors()['data_inicio'][0]);

        // No dia seguinte já não se sobrepõe.
        $this->assertNotNull($this->action()->executar($this->dto($c, $b->id, ['data_inicio' => '2026-07-01']))->id);
    }

    public function test_aceita_substituto_em_simultaneo_com_titular(): void
    {
        $c = $this->cenarioDeDocencia();
        $titular = $this->criarDocente('Docente t');
        $substituto = $this->criarDocente('Docente s');
        $this->action()->executar($this->dto($c, $titular->id));

        $atribuicao = $this->action()->executar($this->dto($c, $substituto->id, [
            'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-04-01', 'data_fim' => '2026-04-30',
        ]));

        $this->assertSame(PapelDocente::SUBSTITUTO, $atribuicao->fresh()->papel);
        $this->assertSame(2, AtribuicaoDocente::count());
    }

    public function test_substituto_sem_titular_e_permitido(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();

        $atribuicao = $this->action()->executar($this->dto($c, $prof->id, ['papel' => PapelDocente::SUBSTITUTO]));

        $this->assertSame('Substituto', $atribuicao->fresh()->papel_descricao);
    }

    public function test_rejeita_mesmo_professor_sobreposto_na_mesma_disciplina(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $this->action()->executar($this->dto($c, $prof->id, ['papel' => PapelDocente::SUBSTITUTO, 'data_fim' => '2026-03-31']));

        $e = $this->rejeitada($this->dto($c, $prof->id, ['data_inicio' => '2026-03-31']), 'docente_id');
        $this->assertSame(ValidadorAtribuicaoDocenteService::MENSAGEM_DOCENTE_SOBREPOSTO, $e->errors()['docente_id'][0]);

        // Noutra disciplina da mesma turma, o mesmo professor pode estar em simultâneo.
        $this->assertNotNull($this->action()->executar($this->dto($c, $prof->id, ['disciplina_id' => $c['outraDisciplina']->id]))->id);
    }

    public function test_ano_lectivo_encerrado_nao_aceita_escrita(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);

        $this->rejeitada($this->dto($c, $prof->id), 'turma_id');
    }

    public function test_data_fim_posterior_ao_fim_do_ano_e_rejeitada(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();

        $this->rejeitada($this->dto($c, $prof->id, ['data_fim' => '2027-01-01']), 'data_fim');

        // O último dia do ano é aceite (extremo inclusivo).
        $this->assertNotNull($this->action()->executar($this->dto($c, $prof->id, ['data_fim' => '2026-12-31']))->id);
    }

    public function test_referencia_de_outro_tenant_e_rejeitada_para_turma_disciplina_e_professor(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$cB, $profB] = $this->noTenant($outro, fn () => [$this->cenarioDeDocencia(), $this->criarDocente('Docente b')]);

        $this->rejeitada($this->dto($c, $prof->id, ['turma_id' => $cB['turma']->id]), 'turma_id');
        $this->rejeitada($this->dto($c, $prof->id, ['disciplina_id' => $cB['disciplina']->id]), 'disciplina_id');
        $this->rejeitada($this->dto($c, $profB->id), 'docente_id');

        $this->assertSame(0, $this->noTenant($outro, fn () => AtribuicaoDocente::count()));
    }
}
