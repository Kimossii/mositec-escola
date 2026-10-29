<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\DisciplinasAtribuiveisService;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\InscreverDisciplinasAutomaticamenteAction;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Tests\TestCase;

class DisciplinasAtribuiveisServiceTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private function servico(): DisciplinasAtribuiveisService
    {
        return app(DisciplinasAtribuiveisService::class);
    }

    private function validador(): ValidadorAtribuicaoDocenteService
    {
        return app(ValidadorAtribuicaoDocenteService::class);
    }

    private function dto(array $c, int $professorId, array $o = []): AtribuicaoDocenteDTO
    {
        return new AtribuicaoDocenteDTO(
            professor_id: $o['professor_id'] ?? $professorId,
            turma_id: $o['turma_id'] ?? $c['turma']->id,
            disciplina_id: $o['disciplina_id'] ?? $c['disciplina']->id,
            papel: $o['papel'] ?? PapelDocente::TITULAR,
            data_inicio: $o['data_inicio'] ?? '2026-03-01',
            data_fim: array_key_exists('data_fim', $o) ? $o['data_fim'] : null,
        );
    }

    private function existente(array $c, int $professorId, array $o = []): AtribuicaoDocente
    {
        return AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id,
            'disciplina_id' => $o['disciplina_id'] ?? $c['disciplina']->id, 'professor_id' => $professorId,
            'papel' => $o['papel'] ?? PapelDocente::TITULAR,
            'data_inicio' => $o['data_inicio'] ?? '2026-03-01', 'data_fim' => $o['data_fim'] ?? null,
        ]);
    }

    private function assertErro(string $chave, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Devia ter lançado ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($chave, $e->errors());
        }
    }

    // --- daTurma ---

    public function test_devolve_as_disciplinas_do_plano_confirmado(): void
    {
        $c = $this->cenarioDeDocencia();

        $ids = $this->servico()->daTurma($c['turma'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$c['disciplina']->id, $c['outraDisciplina']->id], collect($ids)->sort()->values()->all());
        $this->assertInstanceOf(Disciplina::class, $this->servico()->daTurma($c['turma'])->first());
    }

    public function test_vazia_quando_a_turma_nao_tem_plano_confirmado(): void
    {
        $c = $this->cenarioDeDocencia();
        $c['plano']->update(['estado' => 0]);

        $this->assertTrue($this->servico()->daTurma($c['turma'])->isEmpty());
    }

    public function test_ignora_linhas_do_plano_inactivas(): void
    {
        $c = $this->cenarioDeDocencia();
        PlanoCurricularDisciplina::where('plano_curricular_id', $c['plano']->id)
            ->where('disciplina_id', $c['outraDisciplina']->id)->first()->update(['estado' => 0]);

        $ids = $this->servico()->daTurma($c['turma'])->pluck('id')->all();

        $this->assertSame([$c['disciplina']->id], $ids);
    }

    public function test_nao_mistura_disciplinas_de_outro_tenant(): void
    {
        $c = $this->cenarioDeDocencia();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $disciplinaDeB = $this->noTenant($outro, fn () => $this->cenarioDeDocencia()['disciplina']->id);

        $ids = $this->servico()->daTurma($c['turma'])->pluck('id')->all();

        $this->assertNotContains($disciplinaDeB, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_cada_disciplina_aparece_uma_so_vez(): void
    {
        $c = $this->cenarioDeDocencia();
        $plano = PlanoCurricular::with('disciplinas')->find($c['plano']->id);
        $duplicado = $plano->disciplinas->concat($plano->disciplinas); // linhas repetidas
        $plano->setRelation('disciplinas', $duplicado);

        $accao = $this->createMock(InscreverDisciplinasAutomaticamenteAction::class);
        $accao->method('resolverPlanoCurricular')->willReturn($plano);

        $resultado = (new DisciplinasAtribuiveisService($accao))->daTurma($c['turma']);

        $this->assertCount(2, $resultado);
        $this->assertSame($resultado->pluck('id')->unique()->count(), $resultado->count());
    }

    // --- validador ---

    public function test_aceita_atribuicao_valida_e_devolve_a_turma(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();

        $turma = $this->validador()->validar($this->dto($c, $prof->id));

        $this->assertInstanceOf(Turma::class, $turma);
        $this->assertSame($c['turma']->id, $turma->id);
    }

    public function test_aceita_ano_planeado_e_activo(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();
        $c['ano']->update(['estado' => EstadoAnoLectivo::PLANEADO]);

        $this->assertSame($c['turma']->id, $this->validador()->validar($this->dto($c, $prof->id))->id);

        $c['ano']->update(['estado' => EstadoAnoLectivo::ATIVO]);
        $this->assertSame($c['turma']->id, $this->validador()->validar($this->dto($c, $prof->id))->id);
    }

    public function test_rejeita_disciplina_fora_do_plano(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();
        $fora = Disciplina::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'FIS', 'nome' => 'Física']);

        $this->assertErro('disciplina_id', fn () => $this->validador()->validar($this->dto($c, $prof->id, ['disciplina_id' => $fora->id])));
    }

    public function test_rejeita_datas_fora_do_ano_lectivo(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();

        $this->assertErro('data_inicio', fn () => $this->validador()->validar($this->dto($c, $prof->id, ['data_inicio' => '2025-12-31'])));
        $this->assertErro('data_inicio', fn () => $this->validador()->validar($this->dto($c, $prof->id, ['data_inicio' => '2027-01-01'])));
        $this->assertErro('data_fim', fn () => $this->validador()->validar($this->dto($c, $prof->id, ['data_fim' => '2027-01-01'])));
        // extremos inclusivos
        $this->validador()->validar($this->dto($c, $prof->id, ['data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31']));
        $this->addToAssertionCount(1);
    }

    public function test_rejeita_data_fim_anterior_a_data_inicio(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();

        $this->assertErro('data_fim', fn () => $this->validador()->validar($this->dto($c, $prof->id, ['data_inicio' => '2026-05-10', 'data_fim' => '2026-05-09'])));
        $this->validador()->validar($this->dto($c, $prof->id, ['data_inicio' => '2026-05-10', 'data_fim' => '2026-05-10']));
        $this->addToAssertionCount(1);
    }

    public function test_rejeita_turma_inactiva(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();
        $c['turma']->update(['estado' => 0]);

        $this->assertErro('turma_id', fn () => $this->validador()->validar($this->dto($c, $prof->id)));
    }

    public function test_rejeita_turma_inexistente(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();

        $this->assertErro('turma_id', fn () => $this->validador()->validar($this->dto($c, $prof->id, ['turma_id' => 999999])));
    }

    public function test_rejeita_ano_lectivo_encerrado(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);

        $this->assertErro('turma_id', fn () => $this->validador()->validar($this->dto($c, $prof->id)));
    }

    public function test_rejeita_professor_inexistente_inactivo_ou_sem_perfil(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();
        $this->assertErro('professor_id', fn () => $this->validador()->validar($this->dto($c, 999999)));

        $prof->update(['estado' => 0]);
        $this->assertErro('professor_id', fn () => $this->validador()->validar($this->dto($c, $prof->id)));

        $semPerfil = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'segredo123']);
        $this->assertErro('professor_id', fn () => $this->validador()->validar($this->dto($c, $semPerfil->id)));
    }

    public function test_rejeita_professor_de_outro_tenant(): void
    {
        $c = $this->cenarioDeDocencia();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $profB = $this->noTenant($outro, fn () => $this->criarProfessor('b@example.com'));

        $this->assertErro('professor_id', fn () => $this->validador()->validar($this->dto($c, $profB->id)));
    }

    public function test_aceita_professor_com_outros_perfis(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarProfessor();
        $outroPerfil = Role::where('nome', '!=', Perfil::PROFESSOR->value)->first();
        $prof->roles()->attach($outroPerfil->id);

        $this->assertSame($c['turma']->id, $this->validador()->validar($this->dto($c, $prof->id))->id);
    }

    public function test_rejeita_segundo_titular_sobreposto(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarProfessor('a@example.com');
        $b = $this->criarProfessor('b@example.com');
        $this->existente($c, $a->id, ['data_inicio' => '2026-03-01', 'data_fim' => '2026-06-30']);

        $this->assertErro('data_inicio', fn () => $this->validador()->validar($this->dto($c, $b->id, ['data_inicio' => '2026-06-30'])));
        // dia seguinte ao fim do outro: aceite
        $this->validador()->validar($this->dto($c, $b->id, ['data_inicio' => '2026-07-01']));
        // antes do início do outro, com fim aberto, sobrepõe-se
        $this->assertErro('data_inicio', fn () => $this->validador()->validar($this->dto($c, $b->id, ['data_inicio' => '2026-02-01'])));
        // fim exactamente no dia anterior ao início do outro: aceite
        $this->validador()->validar($this->dto($c, $b->id, ['data_inicio' => '2026-02-01', 'data_fim' => '2026-02-28']));
        $this->addToAssertionCount(2);
    }

    public function test_substituto_pode_coexistir_com_titular_de_outro_professor(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarProfessor('a@example.com');
        $b = $this->criarProfessor('b@example.com');
        $this->existente($c, $a->id);

        $turma = $this->validador()->validar($this->dto($c, $b->id, ['papel' => PapelDocente::SUBSTITUTO]));

        $this->assertSame($c['turma']->id, $turma->id);
    }

    public function test_rejeita_mesmo_professor_sobreposto_qualquer_papel(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarProfessor('a@example.com');
        $this->existente($c, $a->id, ['papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-03-01', 'data_fim' => '2026-04-30']);

        $this->assertErro('professor_id', fn () => $this->validador()->validar($this->dto($c, $a->id, ['data_inicio' => '2026-04-30'])));
        $this->assertErro('professor_id', fn () => $this->validador()->validar($this->dto($c, $a->id, ['papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-04-01'])));
        $this->validador()->validar($this->dto($c, $a->id, ['data_inicio' => '2026-05-01']));
        // outra disciplina da mesma turma não colide
        $this->validador()->validar($this->dto($c, $a->id, ['disciplina_id' => $c['outraDisciplina']->id]));
        $this->addToAssertionCount(2);
    }

    public function test_ignora_a_atribuicao_indicada_e_as_eliminadas(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarProfessor('a@example.com');
        $existente = $this->existente($c, $a->id);

        // sem ignorar: colide consigo própria
        $this->assertErro('professor_id', fn () => $this->validador()->validar($this->dto($c, $a->id)));
        // a ignorar: ok
        $this->validador()->validar($this->dto($c, $a->id), $existente);
        // eliminada: ok
        $existente->delete();
        $this->validador()->validar($this->dto($c, $a->id));
        $this->addToAssertionCount(2);
    }

    public function test_sobreposicao_considera_fim_do_ano_para_data_fim_nula(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarProfessor('a@example.com');
        $b = $this->criarProfessor('b@example.com');
        $this->existente($c, $a->id, ['data_inicio' => '2026-03-01', 'data_fim' => null]);

        $this->assertErro('data_inicio', fn () => $this->validador()->validar($this->dto($c, $b->id, ['data_inicio' => '2026-12-31'])));
    }
}
