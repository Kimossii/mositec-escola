<?php

namespace Modules\Core\Exportacao;

use Dompdf\Dompdf;
use Dompdf\Options;

/** PDF A4 (horizontal com muitas colunas): faixa de título, tabela com zebra e rodapé com a numeração das páginas. */
class EscritorPdf
{
    public function escrever(TabelaExportavel $tabela, string $caminho): void
    {
        $opcoes = new Options;
        $opcoes->set('defaultFont', 'DejaVu Sans');
        $opcoes->set('isRemoteEnabled', false);
        $opcoes->set('isPhpEnabled', true); // só para o número das páginas; o conteúdo da tabela é sempre escapado
        $opcoes->set('chroot', sys_get_temp_dir());

        $pdf = new Dompdf($opcoes);
        $pdf->loadHtml($this->html($tabela), 'UTF-8');
        $pdf->setPaper('A4', count($tabela->colunas) > 6 ? 'landscape' : 'portrait');
        $pdf->render();

        file_put_contents($caminho, $pdf->output());
    }

    private function html(TabelaExportavel $tabela): string
    {
        $e = fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $cabecalho = '';
        foreach ($tabela->colunas as $rotulo) {
            $cabecalho .= '<th>'.$e($rotulo).'</th>';
        }

        $corpo = '';
        foreach ($tabela->linhas as $linha) {
            $corpo .= '<tr>';
            foreach (array_keys($tabela->colunas) as $chave) {
                $valor = $linha[$chave] ?? null;
                $corpo .= '<td>'.$e(is_float($valor) ? number_format($valor, 2, ',', '.') : $valor).'</td>';
            }
            $corpo .= '</tr>';
        }

        $assinaturas = '';
        if ($tabela->assinaturas !== []) {
            $assinaturas = '<table class="assinaturas"><tr>';
            foreach ($tabela->assinaturas as $rotulo) {
                $assinaturas .= '<td><div class="assinaturas__linha">'.$e($rotulo).'</div></td>';
            }
            $assinaturas .= '</tr></table><div class="assinaturas__data">Data: ____ / ____ / ________</div>';
        }

        $meta = $e(implode(' · ', array_filter([$tabela->subtitulo, 'Gerado em '.date('d/m/Y H:i')])));

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 28px 30px 40px; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 9px; color: #1e293b; }
            .topo { background: #0f172a; color: #fff; padding: 12px 16px; border-radius: 4px; margin-bottom: 12px; }
            .topo .entidade { font-size: 8px; letter-spacing: 1px; text-transform: uppercase; color: #86efac; }
            .topo h1 { font-size: 16px; margin: 3px 0 2px; }
            .topo .meta { font-size: 8px; color: #cbd5e1; }
            table { width: 100%; border-collapse: collapse; }
            th { background: #166534; color: #fff; text-align: left; padding: 5px 6px; font-size: 8px; }
            td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; word-wrap: break-word; }
            tr:nth-child(even) td { background: #f8fafc; }
            thead { display: table-header-group; }
            .assinaturas { margin-top: 46px; page-break-inside: avoid; }
            .assinaturas td { width: auto; border: 0; padding: 0 22px; background: #fff !important; }
            .assinaturas__linha { border-top: 1px solid #1e293b; padding-top: 4px; text-align: center; font-size: 8px; color: #475569; }
            .assinaturas__data { margin-top: 14px; font-size: 8px; color: #475569; page-break-inside: avoid; }
        </style></head><body>
            <div class="topo"><div class="entidade">'.$e($tabela->entidade).'</div><h1>'.$e($tabela->titulo).'</h1><div class="meta">'.$meta.'</div></div>
            <table><thead><tr>'.$cabecalho.'</tr></thead><tbody>'.$corpo.'</tbody></table>'.$assinaturas.'
            <script type="text/php">if (isset($pdf)) { $pdf->page_text($pdf->get_width() - 80, $pdf->get_height() - 24, "Página {PAGE_NUM} de {PAGE_COUNT}", null, 8, [0.4, 0.45, 0.55]); }</script>
        </body></html>';
    }
}
