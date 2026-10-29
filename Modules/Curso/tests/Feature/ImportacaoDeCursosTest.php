<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Importacao\Jobs\ImportarImportacaoJob;
use Modules\Core\Importacao\LeitorDeFicheiro;
use Modules\Core\Models\Importacao;
use Modules\Curso\Models\Curso;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;
use ZipArchive;

class ImportacaoDeCursosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function como(Perfil $perfil, string $email = 'admin@example.com'): User
    {
        $u = User::firstOrCreate(['email' => $email], ['name' => $email, 'password' => Hash::make('x')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);
        $this->actingAs($u);

        return $u;
    }

    private function csv(string $corpo, string $nome = 'cursos.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nome, "\xEF\xBB\xBF".$corpo);
    }

    private function carregar(string $corpo): Importacao
    {
        $this->post('/importacoes/cursos', ['ficheiro' => $this->csv($corpo)])->assertRedirect();

        return Importacao::latest('id')->firstOrFail();
    }

    private const FICHEIRO = "Código*;Nome*;Descrição\n(exemplo) INF;Exemplo;\nINF;Informática;Curso de TI\nCON;Contabilidade;\nGES;Gestão;\nINF;Outra Informática;\nELE;;\nCON2;Contabilidade;nome repetido\nEXI;Existente;\n\n";

    public function test_fluxo_completo_valida_ignora_o_que_existe_e_os_totais_batem(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        Curso::create(['estabelecimento_id' => \Modules\Estabelecimento\Models\Estabelecimento::current()->id, 'codigo' => 'EXI', 'nome' => 'Existente']);

        $imp = $this->carregar(self::FICHEIRO);

        $this->assertSame(Importacao::VALIDADA, $imp->estado);
        $this->assertSame(['total' => 7, 'validas' => 3, 'ignoradas' => 1, 'com_erro' => 3], $imp->only('total', 'validas', 'ignoradas', 'com_erro'));
        $this->assertSame(1, Curso::count());

        $this->post("/importacoes/{$imp->id}/confirmar")->assertRedirect();
        $imp->refresh();

        $this->assertSame(Importacao::CONCLUIDA, $imp->estado);
        $this->assertSame(3, $imp->importadas);
        $this->assertSame(4, Curso::count());
        $this->assertSame($imp->total, $imp->importadas + $imp->ignoradas + $imp->com_erro);
        $this->assertNotNull(Curso::where('codigo', 'INF')->first()->criado_por);

        $csv = $this->get("/importacoes/{$imp->id}/erros")->assertOk()->streamedContent();
        $this->assertStringContainsString('repetido', $csv);
        $this->assertStringContainsString('Já existe', $csv);
    }

    public function test_reimportar_o_mesmo_ficheiro_nao_altera_nada(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $primeira = $this->carregar("Código*;Nome*\nINF;Informática\nCON;Contabilidade\n");
        $this->post("/importacoes/{$primeira->id}/confirmar");
        $this->assertSame(2, Curso::count());

        $segunda = $this->carregar("Código*;Nome*\nINF;Informática\nCON;Contabilidade\n");

        $this->assertSame(2, $segunda->ignoradas);
        $this->assertSame(0, $segunda->validas);
        $this->post("/importacoes/{$segunda->id}/confirmar")->assertSessionHasErrors('importacao');
        $this->assertSame(2, Curso::count());
    }

    public function test_repetir_o_job_nunca_duplica(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $imp = $this->carregar("Código*;Nome*\nINF;Informática\nCON;Contabilidade\n");
        $this->post("/importacoes/{$imp->id}/confirmar");

        app()->call([new ImportarImportacaoJob($imp->id), 'handle']);
        app()->call([new ImportarImportacaoJob($imp->id), 'handle']);

        $this->assertSame(2, Curso::count());
    }

    public function test_cabecalho_em_falta_falha_com_mensagem_clara(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $imp = $this->carregar("Foo;Bar\n1;2\n");

        $this->assertSame(Importacao::FALHADA, $imp->estado);
        $this->assertStringContainsString('cabeçalho', $imp->erro_geral);
    }

    public function test_so_o_dono_e_quem_pode_criar_cursos_acedem(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $imp = $this->carregar("Código*;Nome*\nINF;Informática\n");

        $this->como(Perfil::ADMIN_ESCOLA, 'outro@example.com');
        $this->get("/importacoes/{$imp->id}")->assertNotFound();

        $this->como(Perfil::PROFESSOR, 'prof@example.com');
        $this->get('/importacoes/cursos/nova')->assertForbidden();
    }

    public function test_modelo_xlsx_do_sistema_e_lido_de_volta_e_o_leitor_aceita_textos_partilhados(): void
    {
        $this->como(Perfil::ADMIN_ESCOLA);
        $resposta = $this->get('/importacoes/cursos/modelo?formato=excel')->assertOk();
        $linhas = iterator_to_array((new LeitorDeFicheiro)->linhas($resposta->baseResponse->getFile()->getPathname(), 'xlsx'));
        $this->assertContains(['Código*', 'Nome*', 'Descrição'], $linhas);

        // Como o Excel grava: textos em sharedStrings, folha com namespace por omissão.
        $caminho = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Código</t></si><si><t>Nome</t></si><si><t>INF</t></si><si><r><t>Infor</t></r><r><t>mática</t></r></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="C2"><v>7</v></c></row></sheetData></worksheet>');
        $zip->close();

        $lidas = iterator_to_array((new LeitorDeFicheiro)->linhas($caminho, 'xlsx'));
        $this->assertSame(['Código', 'Nome'], $lidas[1]);
        $this->assertSame(['INF', null, '7'], $lidas[2]);
        unlink($caminho);
    }

    /** Medição de escala. Só corre com IMPORT_ESCALA=<linhas> (ex.: 20000); fora disso fica saltado. */
    public function test_escala_valida_e_importa_muitas_linhas_sem_perder_nem_duplicar(): void
    {
        $n = (int) env('IMPORT_ESCALA', 0);
        if ($n === 0) {
            $this->markTestSkipped('Defina IMPORT_ESCALA=<linhas> para medir.');
        }

        config(['importacao.maximo_sync_linhas' => PHP_INT_MAX, 'importacao.maximo_sync_bytes' => PHP_INT_MAX]);
        $this->como(Perfil::ADMIN_ESCOLA);
        $corpo = "Código*;Nome*\n";
        for ($i = 1; $i <= $n; $i++) {
            $corpo .= "C{$i};Curso número {$i}\n";
        }
        // Algumas linhas más para provar que nada desaparece: duplicado de código e nome em falta.
        $corpo .= "C1;Outro nome\nC{$n}x;\n";

        $t0 = microtime(true);
        $imp = $this->carregar($corpo);
        $validacao = microtime(true) - $t0;
        $t1 = microtime(true);
        $this->post("/importacoes/{$imp->id}/confirmar");
        $importacao = microtime(true) - $t1;
        $imp->refresh();

        fwrite(STDERR, sprintf("\n[escala] %d linhas: validar %.1fs, importar %.1fs (%.0f linhas/s)\n", $n, $validacao, $importacao, $n / max($importacao, 0.001)));
        $this->assertSame($n, $imp->importadas);
        $this->assertSame(2, $imp->com_erro);
        $this->assertSame($imp->total, $imp->importadas + $imp->ignoradas + $imp->com_erro);
        $this->assertSame($n, Curso::count());
    }
}
