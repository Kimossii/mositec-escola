<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Contracts\ConsultaDocenciaParaHorario;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class ConsultaDocenciaParaHorarioTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private function contrato(): ConsultaDocenciaParaHorario
    {
        return app(ConsultaDocenciaParaHorario::class);
    }

    private function atribuir(array $c, Docente $docente, PapelDocente $papel, string $inicio, ?string $fim = null, ?int $disciplinaId = null): AtribuicaoDocente
    {
        return AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id,
            'disciplina_id' => $disciplinaId ?? $c['disciplina']->id, 'docente_id' => $docente->id,
            'papel' => $papel, 'data_inicio' => $inicio, 'data_fim' => $fim,
        ]);
    }

    public function test_titular_em_devolve_o_titular_em_vigor_e_ignora_substituto(): void
    {
        $c = $this->cenarioDeDocencia();
        $titular = $this->criarDocente('Titular Um');
        $this->atribuir($c, $titular, PapelDocente::TITULAR, '2026-03-01');
        $this->atribuir($c, $this->criarDocente('Substituto'), PapelDocente::SUBSTITUTO, '2026-03-01');

        $r = $this->contrato()->titularEm($c['turma']->id, $c['disciplina']->id, Carbon::parse('2026-06-15'));

        $this->assertSame(['docente_id' => $titular->id, 'nome' => 'Titular Um'], $r);
    }

    public function test_titular_em_devolve_null_sem_titular_ou_apos_fim(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->atribuir($c, $this->criarDocente(), PapelDocente::TITULAR, '2026-03-01', '2026-05-31');

        $this->assertNull($this->contrato()->titularEm($c['turma']->id, $c['disciplina']->id, Carbon::parse('2026-06-01')));
        $this->assertNull($this->contrato()->titularEm($c['turma']->id, $c['disciplina']->id, Carbon::parse('2026-02-01')));
        $this->assertNull($this->contrato()->titularEm($c['turma']->id, $c['outraDisciplina']->id, Carbon::parse('2026-04-01')));
    }

    public function test_pares_do_docente_incluem_substituto(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente();
        $this->atribuir($c, $docente, PapelDocente::TITULAR, '2026-03-01', null, $c['disciplina']->id);
        $this->atribuir($c, $docente, PapelDocente::SUBSTITUTO, '2026-03-01', null, $c['outraDisciplina']->id);

        $pares = $this->contrato()->paresEmVigorDoDocente($docente->id, Carbon::parse('2026-06-15'));

        $this->assertEqualsCanonicalizing([
            ['turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id],
            ['turma_id' => $c['turma']->id, 'disciplina_id' => $c['outraDisciplina']->id],
        ], $pares);
        $this->assertSame([], $this->contrato()->paresEmVigorDoDocente($docente->id, Carbon::parse('2026-01-15')));
    }

    public function test_pares_do_utilizador_resolvem_pela_pessoa_e_conta_sem_docente_da_vazio(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente('Com Conta', comAcesso: true);
        $this->atribuir($c, $docente, PapelDocente::TITULAR, '2026-03-01');
        $utilizador = $docente->utilizador();

        $this->assertSame(
            [['turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id]],
            $this->contrato()->paresEmVigorDoUtilizador($utilizador->id, Carbon::parse('2026-06-15')),
        );

        $semDocente = $this->criarProfessor('semdocente@example.com');
        $this->assertSame([], $this->contrato()->paresEmVigorDoUtilizador($semDocente->id, Carbon::parse('2026-06-15')));
    }

    public function test_docentes_activos_exclui_inactivos_e_ordena_por_nome(): void
    {
        $this->cenarioDeDocencia();
        $b = $this->criarDocente('Bruno');
        $a = $this->criarDocente('Ana');
        $inactivo = $this->criarDocente('Zé Inactivo');
        $inactivo->update(['estado' => 0]);

        $this->assertSame(
            [['id' => $a->id, 'nome' => 'Ana'], ['id' => $b->id, 'nome' => 'Bruno']],
            $this->contrato()->docentesActivos(),
        );
    }

    public function test_nao_ve_atribuicoes_de_outro_tenant(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente();
        $this->atribuir($c, $docente, PapelDocente::TITULAR, '2026-03-01');

        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $r = $this->noTenant($outro, fn () => $this->contrato()->titularEm($c['turma']->id, $c['disciplina']->id, Carbon::parse('2026-06-15')));

        $this->assertNull($r);
        $this->assertSame([], $this->noTenant($outro, fn () => $this->contrato()->paresEmVigorDoDocente($docente->id, Carbon::parse('2026-06-15'))));
    }

    public function test_titulares_no_intervalo_devolvem_cada_titular_recortado(): void
    {
        $c = $this->cenarioDeDocencia();
        $x = $this->criarDocente('X');
        $y = $this->criarDocente('Y');
        $this->atribuir($c, $x, PapelDocente::TITULAR, '2026-01-01', '2026-04-30');
        $this->atribuir($c, $y, PapelDocente::TITULAR, '2026-05-01');
        $this->atribuir($c, $this->criarDocente('Subst'), PapelDocente::SUBSTITUTO, '2026-01-01');

        $r = $this->contrato()->titularesNoIntervalo($c['turma']->id, $c['disciplina']->id, '2026-03-01', '2026-06-30');

        $this->assertSame([
            ['docente_id' => $x->id, 'inicio' => '2026-03-01', 'fim' => '2026-04-30'],
            ['docente_id' => $y->id, 'inicio' => '2026-05-01', 'fim' => '2026-06-30'],
        ], $r);
        $this->assertSame([], $this->contrato()->titularesNoIntervalo($c['turma']->id, $c['outraDisciplina']->id, '2026-03-01', '2026-06-30'));
    }
}
