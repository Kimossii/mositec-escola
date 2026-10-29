<?php

namespace Modules\Docente\Tests\Feature\Pgsql;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Docente\Actions\AtribuirDocenteAction;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/**
 * A tradução das violações de exclusão (23P01) em erros de validação. Para chegar à constraint sem passar pela
 * validação, uma linha em conflito é gravada já depois de validar (no `creating` do model), como faria outra
 * sessão que escapasse ao bloqueio da turma. Corre só com a base mositec_escola_test.
 */
#[Group('pgsql')]
class AtribuirDocenteActionPgsqlTest extends PgsqlTestCase
{
    use CenarioDeDocencia;

    private function dto(array $c, int $docenteId, PapelDocente $papel = PapelDocente::TITULAR): AtribuicaoDocenteDTO
    {
        return new AtribuicaoDocenteDTO(
            docente_id: $docenteId, turma_id: $c['turma']->id, disciplina_id: $c['disciplina']->id,
            papel: $papel, data_inicio: '2026-03-01',
        );
    }

    /** Grava, uma única vez e já depois da validação, uma linha com estes atributos antes da linha da Action. */
    private function gravarConflitoDepoisDeValidar(array $c, array $atributos): void
    {
        $gravado = false;
        AtribuicaoDocente::creating(function () use ($c, $atributos, &$gravado) {
            if ($gravado) {
                return;
            }

            $gravado = true;
            AtribuicaoDocente::create(array_merge([
                'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
                'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-02-01',
            ], $atributos));
        });
    }

    private function erroDeValidacao(AtribuicaoDocenteDTO $dto): ValidationException
    {
        try {
            app(AtribuirDocenteAction::class)->executar($dto);
        } catch (ValidationException $e) {
            return $e;
        }

        $this->fail('Devia ter lançado ValidationException.');
    }

    public function test_pg_violacao_do_titular_unico_vira_erro_de_validacao(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $this->gravarConflitoDepoisDeValidar($c, ['docente_id' => $this->criarDocente('Docente outro')->id]);

        $e = $this->erroDeValidacao($this->dto($c, $prof->id));

        $this->assertSame(['data_inicio' => [ValidadorAtribuicaoDocenteService::MENSAGEM_TITULAR_SOBREPOSTO]], $e->errors());
        // A transacção da Action foi revertida por inteiro (a linha em conflito também) e a ligação continua utilizável.
        $this->assertSame(0, AtribuicaoDocente::count());
    }

    public function test_pg_violacao_do_mesmo_docente_vira_erro_de_validacao(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $this->gravarConflitoDepoisDeValidar($c, ['docente_id' => $prof->id, 'papel' => PapelDocente::SUBSTITUTO]);

        $e = $this->erroDeValidacao($this->dto($c, $prof->id, PapelDocente::SUBSTITUTO));

        $this->assertSame(['docente_id' => [ValidadorAtribuicaoDocenteService::MENSAGEM_DOCENTE_SOBREPOSTO]], $e->errors());
        $this->assertSame(0, AtribuicaoDocente::count());
    }

    public function test_pg_outras_falhas_da_base_propagam_tal_como_estao(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        // Uma FK violada (23503) não é concorrência: não pode ser disfarçada de erro de validação.
        // Closure sem valor de retorno: um `creating` que devolve algo interrompe os restantes ouvintes (ex.: o tenant).
        AtribuicaoDocente::creating(function (AtribuicaoDocente $a): void {
            $a->ano_lectivo_id = 999999;
        });

        try {
            app(AtribuirDocenteAction::class)->executar($this->dto($c, $prof->id));
            $this->fail('Devia ter lançado QueryException.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0] ?? null);
        }
    }
}
