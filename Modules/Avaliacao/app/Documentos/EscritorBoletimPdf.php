<?php

namespace Modules\Avaliacao\Documentos;

use Dompdf\Dompdf;
use Dompdf\Options;

/** PDF A4 vertical com um boletim por página: cabeçalho oficial, identificação, notas, observações, resultado e assinaturas. */
class EscritorBoletimPdf
{
    /** @param  list<DocumentoBoletim>  $boletins */
    public function escrever(array $boletins, string $caminho): void
    {
        $opcoes = new Options;
        $opcoes->set('defaultFont', 'DejaVu Sans');
        $opcoes->set('isRemoteEnabled', false);
        $opcoes->set('chroot', sys_get_temp_dir());

        $pdf = new Dompdf($opcoes);
        $pdf->loadHtml($this->html($boletins), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        file_put_contents($caminho, $pdf->output());
    }

    /** @param  list<DocumentoBoletim>  $boletins */
    private function html(array $boletins): string
    {
        $e = fn (mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $paginas = [];

        foreach ($boletins as $i => $b) {
            $cabecalho = $b->logotipo ? '<img class="logo" src="'.$e($b->logotipo).'">' : '';
            foreach ($b->linhasCabecalho as $k => $linha) {
                $cabecalho .= '<div class="oficial'.($k === count($b->linhasCabecalho) - 1 ? ' escola' : '').'">'.$e($linha).'</div>';
            }
            if ($b->lema) {
                $cabecalho .= '<div class="lema">«'.$e($b->lema).'»</div>';
            }

            $identificacao = implode('', array_map(fn (array $d) => '<tr><td class="rotulo">'.$e($d[0]).'</td><td>'.$e($d[1]).'</td></tr>', $b->identificacao));
            $cabecalhoTabela = implode('', array_map(fn (string $c) => '<th>'.$e($c).'</th>', $b->colunas));
            $corpo = implode('', array_map(fn (array $l) => '<tr>'.implode('', array_map(fn ($c, $k) => '<td'.($k === 0 ? ' class="disciplina"' : '').'>'.$e($c).'</td>', $l, array_keys($l))).'</tr>', $b->linhas));
            $observacoes = $b->observacoes === [] ? '' : '<div class="observacoes"><b>Observações do educador</b>'.implode('', array_map(fn (string $o) => '<p>'.$e($o).'</p>', $b->observacoes)).'</div>';
            $assinaturas = '<table class="assinaturas"><tr>'.implode('', array_map(fn (array $a) => '<td><div class="linha">'.$e($a['rotulo']).'</div>'.($a['nome'] ? '<div class="quem">'.$e($a['nome']).'</div>' : '').'</td>', $b->assinaturas)).'</tr></table>';

            $paginas[] = '<div class="pagina"'.($i < count($boletins) - 1 ? ' style="page-break-after: always;"' : '').'>
                <div class="topo">'.$cabecalho.'</div>
                <h1>'.$e($b->titulo).'</h1>
                <table class="identificacao">'.$identificacao.'</table>
                <table class="notas"><thead><tr>'.$cabecalhoTabela.'</tr></thead><tbody>'.$corpo.'</tbody></table>
                <div class="resultado'.($b->resultadoDefinitivo ? '' : ' provisorio').'">Resultado: <b>'.$e($b->resultado).'</b></div>'
                .$observacoes.$assinaturas.'<div class="nota">'.$e($b->nota).'</div></div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
            @page { margin: 30px 34px; }
            body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; color: #000; }
            .topo { text-align: center; margin-bottom: 8px; }
            .logo { height: 54px; margin-bottom: 3px; }
            .oficial { font-size: 10px; font-weight: bold; line-height: 1.4; }
            .oficial.escola { font-size: 11px; }
            .lema { font-size: 8.5px; font-style: italic; margin-top: 2px; }
            h1 { text-align: center; font-size: 13px; margin: 12px 0 10px; letter-spacing: 1px; }
            table { width: 100%; border-collapse: collapse; }
            .identificacao { margin-bottom: 12px; }
            .identificacao td { padding: 2px 4px; }
            .identificacao .rotulo { width: 26%; font-weight: bold; }
            .notas th, .notas td { border: 1px solid #000; padding: 4px 3px; text-align: center; }
            .notas th { font-weight: bold; text-transform: uppercase; font-size: 9px; }
            .notas td.disciplina { text-align: left; padding-left: 6px; }
            .resultado { margin: 12px 0; font-size: 11px; text-align: center; border: 1px solid #000; padding: 6px; }
            .resultado.provisorio { font-style: italic; border-style: dashed; }
            .observacoes { margin: 10px 0; border: 1px solid #000; padding: 6px 8px; }
            .observacoes p { margin: 3px 0; }
            .assinaturas { margin-top: 46px; page-break-inside: avoid; table-layout: fixed; }
            .assinaturas td { text-align: center; padding: 0 14px; }
            .assinaturas .linha { border-top: 1px solid #000; padding-top: 3px; font-size: 8.5px; }
            .assinaturas .quem { font-size: 8.5px; font-weight: bold; margin-top: 2px; }
            .nota { margin-top: 14px; font-size: 8px; font-style: italic; text-align: center; }
        </style></head><body>'.implode('', $paginas).'</body></html>';
    }
}
