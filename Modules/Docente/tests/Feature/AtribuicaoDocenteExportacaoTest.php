<?php

namespace Modules\Docente\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Core\Exportacao\Exportador;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Docente\Services\AtribuicaoDocenteConsultaService;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtribuicaoDocenteExportacaoTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'));
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function como(Perfil $perfil): User
    {
        $u = User::create(['name' => $perfil->value, 'email' => $perfil->value.'@example.com', 'password' => Hash::make('x')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);
        $this->actingAs($u);

        return $u;
    }

    private function atribuir(array $c, Docente $prof, PapelDocente $papel, string $inicio, ?string $fim = null, ?int $disciplinaId = null): AtribuicaoDocente
    {
        return AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $disciplinaId ?? $c['disciplina']->id,
            'docente_id' => $prof->id, 'papel' => $papel, 'data_inicio' => $inicio, 'data_fim' => $fim,
        ]);
    }

    private function csv(string $url): string
    {
        $resposta = $this->get($url)->assertOk();

        return file_get_contents($resposta->baseResponse->getFile()->getPathname());
    }

    public function test_exporta_com_os_filtros_da_lista_e_as_colunas_certas(): void
    {
        $c = $this->cenarioDeDocencia();
        $ana = $this->criarDocente('Ana Docente');
        $rui = $this->criarDocente('Rui Docente');
        $this->atribuir($c, $ana, PapelDocente::TITULAR, '2026-02-01');
        $this->atribuir($c, $rui, PapelDocente::SUBSTITUTO, '2026-08-01', null, $c['outraDisciplina']->id);
        $this->como(Perfil::ADMIN_ESCOLA);

        $todas = $this->csv(route('atribuicoes-docentes.exportar', ['formato' => 'csv']));
        $this->assertStringContainsString('Docente;Turma;Disciplina;Papel;Início;Fim;Estado', $todas);
        $this->assertStringContainsString('Ana Docente', $todas);
        $this->assertStringContainsString('Rui Docente', $todas);
        $this->assertStringContainsString('Titular', $todas);
        $this->assertStringContainsString('Turma 10A', $todas);
        $this->assertStringContainsString('Matemática', $todas);
        $this->assertStringContainsString('2026-02-01', $todas);

        $porProfessor = $this->csv(route('atribuicoes-docentes.exportar', ['formato' => 'csv', 'docente_id' => $rui->id]));
        $this->assertStringContainsString('Rui Docente', $porProfessor);
        $this->assertStringNotContainsString('Ana Docente', $porProfessor);
        $this->assertStringContainsString('Agendada', $porProfessor);

        $porPapel = $this->csv(route('atribuicoes-docentes.exportar', ['formato' => 'csv', 'papel' => PapelDocente::TITULAR->value]));
        $this->assertStringContainsString('Ana Docente', $porPapel);
        $this->assertStringNotContainsString('Rui Docente', $porPapel);
        $this->assertStringContainsString('Em curso', $porPapel);

        $porEstado = $this->csv(route('atribuicoes-docentes.exportar', ['formato' => 'csv', 'estado_vigencia' => 'agendada']));
        $this->assertStringContainsString('Rui Docente', $porEstado);
        $this->assertStringNotContainsString('Ana Docente', $porEstado);

        $porPesquisa = $this->csv(route('atribuicoes-docentes.exportar', ['formato' => 'csv', 'pesquisa' => 'Portug']));
        $this->assertStringContainsString('Rui Docente', $porPesquisa);
        $this->assertStringNotContainsString('Ana Docente', $porPesquisa);
    }

    public function test_exporta_em_todos_os_formatos(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->atribuir($c, $this->criarDocente(), PapelDocente::TITULAR, '2026-02-01');
        $this->como(Perfil::ADMIN_ESCOLA);

        foreach (['excel' => 'spreadsheetml', 'pdf' => 'application/pdf', 'csv' => 'text/csv', 'zip' => 'application/zip'] as $formato => $mime) {
            $resposta = $this->get(route('atribuicoes-docentes.exportar', ['formato' => $formato]))->assertOk();
            $this->assertStringContainsString($mime, $resposta->headers->get('content-type'));
            $this->assertStringContainsString('atribuicoes-docentes-', $resposta->headers->get('content-disposition'));
        }

        $this->get(route('atribuicoes-docentes.exportar', ['formato' => 'docx']))->assertStatus(422);
        $this->get(route('atribuicoes-docentes.exportar'))->assertSessionHasErrors('formato');
    }

    public function test_funcionario_nao_exporta(): void
    {
        $this->cenarioDeDocencia();
        $this->como(Perfil::FUNCIONARIO);

        $this->get(route('atribuicoes-docentes.exportar', ['formato' => 'csv']))->assertForbidden();
    }

    public function test_professor_aluno_e_encarregado_recebem_403(): void
    {
        $this->cenarioDeDocencia();

        foreach ([Perfil::PROFESSOR, Perfil::ALUNO, Perfil::ENCARREGADO] as $perfil) {
            $this->como($perfil);
            $this->get(route('atribuicoes-docentes.exportar', ['formato' => 'csv']))->assertForbidden($perfil->value);
        }
    }

    public function test_respeita_o_limite_do_exportador(): void
    {
        $this->cenarioDeDocencia();
        $this->como(Perfil::ADMIN_ESCOLA);

        $linha = ['docente' => 'P', 'turma' => 'T', 'disciplina' => 'D', 'papel' => 'Titular', 'inicio' => '2026-01-01', 'fim' => '', 'estado' => 'Em curso'];
        $this->partialMock(AtribuicaoDocenteConsultaService::class, function ($mock) use ($linha) {
            $mock->shouldReceive('paraExportacao')
                ->once()
                ->with(Mockery::type('array'), Exportador::MAXIMO_LINHAS + 1)
                ->andReturn(array_fill(0, Exportador::MAXIMO_LINHAS + 1, $linha));
        });

        $this->get(route('atribuicoes-docentes.exportar', ['formato' => 'csv']))->assertStatus(422);
    }

    public function test_nao_vaza_atribuicoes_de_outro_tenant(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->atribuir($c, $this->criarDocente('Docente Escola A'), PapelDocente::TITULAR, '2026-02-01');

        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            $cb = $this->cenarioDeDocencia();
            $this->atribuir($cb, $this->criarDocente('Docente Escola B'), PapelDocente::TITULAR, '2026-02-01');
        });

        $this->como(Perfil::ADMIN_ESCOLA);

        $csv = $this->csv(route('atribuicoes-docentes.exportar', ['formato' => 'csv']));
        $this->assertStringContainsString('Docente Escola A', $csv);
        $this->assertStringNotContainsString('Docente Escola B', $csv);
    }

    public function test_estado_de_um_ano_encerrado_usa_a_referencia_do_ano(): void
    {
        $c = $this->cenarioDeDocencia();
        // «Hoje» já passou o fim do ano: a referência é o último dia do ano (2026-12-31), em que esta atribuição (fim a 2026-12-31) ainda está em curso; com «hoje» estaria terminada.
        Carbon::setTestNow(CarbonImmutable::parse('2027-03-10 10:00:00', 'UTC'));
        $this->atribuir($c, $this->criarDocente('Docente a'), PapelDocente::TITULAR, '2026-02-01', '2026-12-31');
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        $this->como(Perfil::ADMIN_ESCOLA);

        $csv = $this->csv(route('atribuicoes-docentes.exportar', ['formato' => 'csv', 'ano_lectivo_id' => $c['ano']->id]));

        $this->assertStringContainsString('Em curso', $csv);
        $this->assertStringNotContainsString('Terminada', $csv);
    }
}
