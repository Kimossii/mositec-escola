<?php

namespace Modules\Core\Tests\Unit\Exportacao;

use Modules\Core\Exportacao\EscritorCsv;
use Modules\Core\Exportacao\Exportador;
use Modules\Core\Exportacao\TabelaExportavel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class ExportadorTest extends TestCase
{
    private function tabela(int $linhas = 2): TabelaExportavel
    {
        $dados = [['nome' => 'Ana Édi <b>', 'valor' => 1500, 'nota' => '=HYPERLINK("x")'], ['nome' => 'Bruno & Cia', 'valor' => 2.5, 'nota' => '+244 923 000 000']];

        return new TabelaExportavel('Utilizadores', ['nome' => 'Nome', 'valor' => 'Valor', 'nota' => 'Nota'], array_slice(array_pad($dados, $linhas, $dados[0]), 0, $linhas), 'Filtro: todos', 'Escola Teste');
    }

    private function conteudo(\Symfony\Component\HttpFoundation\BinaryFileResponse $resposta): string
    {
        return file_get_contents($resposta->getFile()->getPathname());
    }

    public function test_csv_tem_bom_cabecalho_e_neutraliza_formulas_sem_estragar_telefones(): void
    {
        $resposta = app(Exportador::class)->responder($this->tabela(), 'csv', 'Utilizadores');
        $csv = $this->conteudo($resposta);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Nome;Valor;Nota', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('+244 923 000 000', $csv);
        $this->assertStringNotContainsString("'+244", $csv);
        $this->assertStringContainsString('csv', $resposta->headers->get('content-disposition'));
        $this->assertSame("'=x", EscritorCsv::segura('=x'));
        $this->assertSame(10, EscritorCsv::segura(10));
        $this->assertSame('2,50', EscritorCsv::segura(2.5));
    }

    public function test_excel_e_um_zip_valido_com_o_texto_escapado_e_numeros_como_numeros(): void
    {
        $resposta = app(Exportador::class)->responder($this->tabela(), 'excel', 'Utilizadores');
        $caminho = $resposta->getFile()->getPathname();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($caminho));
        $folha = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertNotFalse($zip->getFromName('xl/workbook.xml'));
        $zip->close();

        $this->assertNotFalse(simplexml_load_string($folha));
        $this->assertStringContainsString('Ana Édi &lt;b&gt;', $folha);
        $this->assertStringContainsString('<v>1500</v>', $folha);
        $this->assertStringContainsString('=HYPERLINK', $folha); // texto inline: o Excel não o executa
        $this->assertStringContainsString('inlineStr', $folha);
    }

    public function test_pdf_comeca_por_assinatura_pdf(): void
    {
        $resposta = app(Exportador::class)->responder($this->tabela(), 'pdf', 'Utilizadores');

        $this->assertStringStartsWith('%PDF', $this->conteudo($resposta));
    }

    public function test_zip_traz_excel_csv_e_pdf(): void
    {
        $resposta = app(Exportador::class)->responder($this->tabela(), 'zip', 'Utilizadores');

        $zip = new ZipArchive;
        $zip->open($resposta->getFile()->getPathname());
        $nomes = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => pathinfo($zip->getNameIndex($i), PATHINFO_EXTENSION))->sort()->values()->all();
        $zip->close();

        $this->assertSame(['csv', 'pdf', 'xlsx'], $nomes);
    }

    public function test_formato_invalido_e_demasiados_registos_sao_recusados(): void
    {
        try {
            app(Exportador::class)->responder($this->tabela(), 'docx', 'x');
            $this->fail('devia recusar o formato');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->expectException(HttpException::class);
        app(Exportador::class)->responder($this->tabela(Exportador::MAXIMO_LINHAS_PDF + 1), 'pdf', 'x');
    }

    public function test_todo_o_pdf_leva_espacos_de_assinatura_por_defeito_e_podem_ser_personalizados(): void
    {
        $this->assertSame(['Elaborado por', 'Responsável'], $this->tabela()->assinaturas);

        $propria = new TabelaExportavel('X', ['a' => 'A'], [['a' => 1]], null, null, ['Elaborado por', 'Verificado por', 'O Director']);
        $resposta = app(Exportador::class)->responder($propria, 'pdf', 'x');

        $this->assertStringStartsWith('%PDF', file_get_contents($resposta->getFile()->getPathname()));
        $this->assertSame([], (new TabelaExportavel('X', ['a' => 'A'], [], assinaturas: []))->assinaturas);
    }
}
