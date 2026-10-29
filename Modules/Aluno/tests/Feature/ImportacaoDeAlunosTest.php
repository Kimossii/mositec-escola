<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Models\Importacao;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ImportacaoDeAlunosTest extends TestCase
{
    use RefreshDatabase;

    public function test_importa_fichas_reutiliza_pessoa_existente_e_ignora_quem_ja_e_aluno(): void
    {
        Storage::fake('local');
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);

        // Docente que também vai ser aluno: a mesma pessoa é reutilizada.
        $docente = DadosPessoa::create(['nome_completo' => 'Rui Docente', 'numero_identificacao' => 'DOC001', 'data_nascimento' => '1990-01-01', 'sexo' => 1, 'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR]);
        // Aluno já existente.
        $this->post('/importacoes/alunos', ['ficheiro' => UploadedFile::fake()->createWithContent('a0.csv', "\xEF\xBB\xBFNome completo*;Nº de identificação (BI)*;Data de nascimento*\nJá Existe;JA001;01/02/2011\n")]);
        $this->post('/importacoes/'.Importacao::latest('id')->first()->id.'/confirmar');
        $this->assertSame(1, Aluno::count());

        $csv = "Nome completo*;Nº de identificação (BI)*;Data de nascimento*;Sexo;Telefone;E-mail\n"
            ."Ana Silva;001 aaa 01;15/03/2010;F;+244 923 000 000;ana@example.com\n"   // ok, BI normalizado
            ."Bruno Costa;BBB002;2011-07-01;M;;\n"                                       // ok, data ISO
            ."Carla Dias;CCC003;40000;;;\n"                                              // ok, série Excel (2009-07-06)
            ."Rui Docente;DOC001;01/01/1990;M;;\n"                                       // reutiliza a pessoa do docente
            ."Já Existe;JA001;01/02/2011;;;\n"                                           // já é aluno: ignorada
            ."Duplo;BBB002;01/01/2010;;;\n"                                              // BI repetido no ficheiro
            ."Futuro;FFF004;01/01/2999;;;\n"                                             // data futura
            ."Sem Data;SSS005;32/13/2010;;;\n"                                           // data inválida
            ."Sexo Mau;XXX006;01/01/2010;Z;;\n"                                          // sexo inválido
            .";YYY007;01/01/2010;;;\n";                                                   // sem nome

        $this->post('/importacoes/alunos', ['ficheiro' => UploadedFile::fake()->createWithContent('a.csv', "\xEF\xBB\xBF".$csv)]);
        $imp = Importacao::latest('id')->first();

        $this->assertSame(['total' => 10, 'validas' => 4, 'ignoradas' => 1, 'com_erro' => 5], $imp->only('total', 'validas', 'ignoradas', 'com_erro'));

        $this->post("/importacoes/{$imp->id}/confirmar");

        $this->assertSame(5, Aluno::count());
        $ana = DadosPessoa::where('numero_identificacao', '001AAA01')->first();
        $this->assertSame('2010-03-15', $ana->data_nascimento->toDateString());
        $this->assertSame(DadosPessoa::SEXO_FEMININO, (int) $ana->sexo);
        $this->assertSame('2009-07-06', DadosPessoa::where('numero_identificacao', 'CCC003')->first()->data_nascimento->toDateString());
        $this->assertSame($docente->id, Aluno::where('dados_pessoa_id', $docente->id)->firstOrFail()->dados_pessoa_id);
        $this->assertSame(1, DadosPessoa::where('numero_identificacao', 'DOC001')->count());
        $this->assertNotNull(Aluno::first()->numero_matricula);
        $this->assertSame(0, User::where('email', 'ana@example.com')->count()); // nenhuma conta criada
    }
}
