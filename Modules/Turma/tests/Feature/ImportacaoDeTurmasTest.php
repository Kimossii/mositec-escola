<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Models\Importacao;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ImportacaoDeTurmasTest extends TestCase
{
    use RefreshDatabase;

    public function test_importa_turmas_resolvendo_referencias_por_nome_e_codigo(): void
    {
        Storage::fake('local');
        $this->seed(PermissaoDatabaseSeeder::class);
        $e = Estabelecimento::current()->id;
        AnoLectivo::create(['estabelecimento_id' => $e, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::PLANEADO]);
        NivelAcademico::create(['estabelecimento_id' => $e, 'codigo' => '10C', 'nome' => '10ª Classe', 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 10]);
        NivelAcademico::create(['estabelecimento_id' => $e, 'codigo' => '1C', 'nome' => '1ª Classe', 'etapa_ensino' => 3, 'ordem' => 1]);
        Curso::create(['estabelecimento_id' => $e, 'codigo' => 'INF', 'nome' => 'Informática']);
        Turno::create(['estabelecimento_id' => $e, 'nome' => 'Manhã']);

        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);

        $csv = "Código*;Nome*;Ano lectivo*;Nível académico*;Curso;Turno\n"
            ."10A;10.ª A;2026/2027;10ª Classe;INF;manhã\n"      // ok (sem distinguir maiúsculas)
            ."10A;Repetida;2026/2027;10C;INF;\n"               // repetida no ficheiro
            ."10B;10.ª B;2026/2027;10C;;\n"                    // secundário sem curso
            ."1A;1.ª A;2026/2027;1C;;\n"                       // ok: primário sem curso
            ."X1;X;2099/2100;10C;INF;\n"                       // ano inexistente
            ."X2;X;2026/2027;10C;ZZZ;Tarde\n";                 // curso e turno inexistentes

        $this->post('/importacoes/turmas', ['ficheiro' => UploadedFile::fake()->createWithContent('t.csv', "\xEF\xBB\xBF".$csv)])->assertRedirect();
        $imp = Importacao::latest('id')->first();

        $this->assertSame(['total' => 6, 'validas' => 2, 'ignoradas' => 0, 'com_erro' => 4], $imp->only('total', 'validas', 'ignoradas', 'com_erro'));

        $this->post("/importacoes/{$imp->id}/confirmar");

        $this->assertSame(2, Turma::count());
        $turma = Turma::where('codigo', '10A')->first();
        $this->assertSame('Manhã', $turma->turno->nome);
        $this->assertSame('INF', $turma->curso->codigo);

        // Reimportar não duplica: as duas passam a «já existe».
        $this->post('/importacoes/turmas', ['ficheiro' => UploadedFile::fake()->createWithContent('t.csv', "\xEF\xBB\xBF".$csv)]);
        $this->assertSame(3, Importacao::latest("id")->first()->ignoradas);
        $this->assertSame(2, Turma::count());
    }
}
