<?php

namespace Modules\Avaliacao\Documentos;

use Dompdf\Dompdf;
use Dompdf\Options;

/** PDF A4 horizontal no formato das pautas oficiais: cabeçalho centrado, tabela com grupos de colunas e assinaturas. */
class EscritorPautaPdf
{
    public function escrever(DocumentoPauta $doc, string $caminho): void
    {
        $opcoes = new Options;
        $opcoes->set('defaultFont', 'DejaVu Sans');
        $opcoes->set('isRemoteEnabled', false);
        $opcoes->set('chroot', sys_get_temp_dir());

        $pdf = new Dompdf($opcoes);
        $pdf->loadHtml($this->html($doc), 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        // O número das páginas põe-se depois de desenhar, no canvas: o dompdf não precisa de executar PHP do documento.
        $canvas = $pdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 80, $canvas->get_height() - 22, 'Página {PAGE_NUM} de {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0, 0, 0]);

        file_put_contents($caminho, $pdf->output());
    }

    private function html(DocumentoPauta $doc): string
    {
        $e = fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

        $cabecalho = $doc->logotipo ? '<img class="logo" src="'.$e($doc->logotipo).'">' : '';
        foreach ($doc->linhasCabecalho as $i => $linha) {
            $cabecalho .= '<div class="oficial'.($i === count($doc->linhasCabecalho) - 1 ? ' escola' : '').'">'.$e($linha).'</div>';
        }
        if ($doc->lema) {
            $cabecalho .= '<div class="lema">«'.$e($doc->lema).'»</div>';
        }

        $meta = implode('', array_map(fn (array $m) => '<span><b>'.$e($m[0]).':</b> '.$e($m[1]).'</span>', $doc->meta));

        $linha1 = '';
        $linha2 = '';
        foreach ($doc->fixas as $rotulo) {
            $linha1 .= '<th rowspan="2" class="fixa">'.$e($rotulo).'</th>';
        }
        foreach ($doc->grupos as $grupo) {
            $linha1 .= '<th colspan="'.count($grupo['colunas']).'">'.$e($grupo['rotulo']).'</th>';
            foreach ($grupo['colunas'] as $coluna) {
                $linha2 .= '<th class="sub">'.$e($coluna).'</th>';
            }
        }
        foreach ($doc->finais as $rotulo) {
            $linha1 .= '<th rowspan="2" class="fixa">'.$e($rotulo).'</th>';
        }

        $corpo = '';
        $nomes = $doc->indiceDoNome();
        foreach ($doc->linhas as $linha) {
            $corpo .= '<tr>';
            foreach ($linha as $i => $celula) {
                $corpo .= '<td'.($i === $nomes ? ' class="nome"' : '').'>'.$e($celula).'</td>';
            }
            $corpo .= '</tr>';
        }

        $assinaturas = '<table class="assinaturas"><tr>';
        foreach ($doc->assinaturas as $a) {
            $assinaturas .= '<td><div class="linha">'.$e($a['rotulo']).'</div>'.($a['nome'] ? '<div class="quem">'.$e($a['nome']).'</div>' : '').'</td>';
        }
        $assinaturas .= '</tr></table>';

        $tamanho = $doc->totalDeColunas() > 40 ? 6 : ($doc->totalDeColunas() > 24 ? 7 : 8);

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 22px 24px 34px; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: '.$tamanho.'px; color: #000; }
            .topo { text-align: center; margin-bottom: 6px; }
            .logo { height: 48px; margin-bottom: 3px; }
            .oficial { font-size: 9px; font-weight: bold; line-height: 1.35; }
            .oficial.escola { font-size: 10px; }
            .lema { font-size: 8px; font-style: italic; margin-top: 2px; }
            h1 { text-align: center; font-size: 12px; margin: 8px 0 4px; letter-spacing: 1px; }
            .meta { text-align: center; margin-bottom: 6px; font-size: 8px; }
            .meta span { margin: 0 8px; }
            table.pauta { width: 100%; border-collapse: collapse; }
            table.pauta th, table.pauta td { border: 1px solid #000; padding: 2px 2px; text-align: center; }
            table.pauta th { font-weight: bold; text-transform: uppercase; }
            table.pauta th.sub { font-weight: normal; }
            table.pauta td.nome { text-align: left; white-space: nowrap; padding-left: 4px; }
            thead { display: table-header-group; }
            tr { page-break-inside: avoid; }
            .nota { margin-top: 6px; font-size: 8px; font-style: italic; text-align: center; }
            .assinaturas { width: 100%; margin-top: 34px; page-break-inside: avoid; }
            .assinaturas td { text-align: center; padding: 0 30px; }
            .assinaturas .linha { border-top: 1px solid #000; padding-top: 3px; font-size: 8px; }
            .assinaturas .quem { font-size: 8px; font-weight: bold; margin-top: 2px; }
        </style></head><body>
            <div class="topo">'.$cabecalho.'</div>
            <h1>'.$e($doc->titulo).'</h1>
            <div class="meta">'.$meta.'</div>
            <table class="pauta"><thead><tr>'.$linha1.'</tr><tr>'.$linha2.'</tr></thead><tbody>'.$corpo.'</tbody></table>'
            .($doc->nota ? '<div class="nota">'.$e($doc->nota).'</div>' : '').$assinaturas.'
        </body></html>';
    }
}
