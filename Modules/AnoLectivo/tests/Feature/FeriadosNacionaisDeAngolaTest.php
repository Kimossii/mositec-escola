<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Actions\ImportarFeriadosNacionaisAction;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Support\FeriadosNacionaisDeAngola;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class FeriadosNacionaisDeAngolaTest extends TestCase
{
    use RefreshDatabase;

    private function ano(string $inicio, string $fim, string $nome = 'Ano'): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'nome' => $nome, 'data_inicio' => $inicio,
            'data_fim' => $fim, 'estado' => EstadoAnoLectivo::PLANEADO,
        ]);
    }

    public function test_lista_do_ano_com_os_fixos_e_os_moveis_calculados(): void
    {
        $datas = array_column((new FeriadosNacionaisDeAngola())->doAno(2026), 'data');

        $this->assertSame([
            '2026-01-01', '2026-02-04', '2026-02-17', '2026-03-08', '2026-04-03', '2026-04-04',
            '2026-05-01', '2026-09-17', '2026-11-02', '2026-11-11', '2026-12-25',
        ], $datas);
    }

    public function test_carnaval_e_sexta_feira_santa_acompanham_a_pascoa(): void
    {
        $nomes = fn (int $ano) => array_column((new FeriadosNacionaisDeAngola())->doAno($ano), 'nome', 'data');

        $this->assertSame('Carnaval', $nomes(2027)['2027-02-09']);
        $this->assertSame('Sexta-feira Santa', $nomes(2027)['2027-03-26']);
        $this->assertSame('Carnaval', $nomes(2025)['2025-03-04']);
        $this->assertSame('Sexta-feira Santa', $nomes(2025)['2025-04-18']);
    }

    public function test_importar_cria_so_os_do_intervalo_do_ano_lectivo_que_atravessa_dois_anos_civis(): void
    {
        $ano = $this->ano('2026-09-01', '2027-07-31');

        $r = app(ImportarFeriadosNacionaisAction::class)->executar($ano);

        $this->assertSame(['criados' => 11, 'existentes' => 0], $r);
        $eventos = EventoCalendario::where('ano_lectivo_id', $ano->id)->orderBy('data_inicio')->get();
        $this->assertSame(
            ['2026-09-17', '2026-11-02', '2026-11-11', '2026-12-25', '2027-01-01', '2027-02-04', '2027-02-09', '2027-03-08', '2027-03-26', '2027-04-04', '2027-05-01'],
            $eventos->map(fn ($e) => $e->data_inicio->toDateString())->all(),
        );
        $this->assertSame([TipoEventoCalendario::FERIADO], $eventos->pluck('tipo')->unique()->values()->all());
        $this->assertTrue($eventos->every(fn ($e) => $e->dia_inteiro && $e->data_inicio->equalTo($e->data_fim)));
        $this->assertSame('Dia da Independência Nacional', $eventos->firstWhere(fn ($e) => $e->data_inicio->toDateString() === '2026-11-11')->titulo);
    }

    public function test_importar_de_novo_nao_duplica(): void
    {
        $ano = $this->ano('2026-01-01', '2026-12-31');
        $acao = app(ImportarFeriadosNacionaisAction::class);

        $acao->executar($ano);
        $r = $acao->executar($ano);

        $this->assertSame(['criados' => 0, 'existentes' => 11], $r);
        $this->assertSame(11, EventoCalendario::where('ano_lectivo_id', $ano->id)->count());
    }

    public function test_um_feriado_ou_ferias_ja_registado_a_cobrir_o_dia_conta_como_existente(): void
    {
        $ano = $this->ano('2026-01-01', '2026-12-31');
        EventoCalendario::create(['ano_lectivo_id' => $ano->id, 'titulo' => 'Férias de Natal', 'tipo' => TipoEventoCalendario::FERIAS, 'data_inicio' => '2026-12-20', 'data_fim' => '2026-12-31', 'dia_inteiro' => true]);
        EventoCalendario::create(['ano_lectivo_id' => $ano->id, 'titulo' => 'Dia da Mulher (escola)', 'tipo' => TipoEventoCalendario::FERIADO, 'data_inicio' => '2026-03-08', 'data_fim' => '2026-03-08', 'dia_inteiro' => true]);

        $r = app(ImportarFeriadosNacionaisAction::class)->executar($ano);

        $this->assertSame(['criados' => 9, 'existentes' => 2], $r);
    }

    public function test_http_so_quem_pode_criar_e_diz_quantos_criou(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $ano = $this->ano('2026-01-01', '2026-12-31');
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $professor = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->first()->id);

        $this->actingAs($professor)->post(route('ano-lectivos.feriados-nacionais', $ano))->assertForbidden();
        $this->assertSame(0, EventoCalendario::count());

        $this->actingAs($admin)->post(route('ano-lectivos.feriados-nacionais', $ano))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '11 feriados nacionais adicionados ao calendário.');
        $this->actingAs($admin)->post(route('ano-lectivos.feriados-nacionais', $ano))
            ->assertSessionHas('success', 'Os feriados nacionais já estavam todos no calendário.');
    }
}
