<?php

namespace Modules\Avaliacao\Documentos;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Gera o PDF ou o Excel de uma pauta e devolve a descarga; o ficheiro temporário apaga-se depois de enviado. */
class ExportadorDePautas
{
    public function __construct(private EscritorPautaPdf $pdf, private EscritorPautaXlsx $xlsx, private EscritorBoletimPdf $boletins)
    {
    }

    /** @param  list<DocumentoBoletim>  $boletins um por aluno, cada um na sua página */
    public function responderBoletins(array $boletins, string $nomeBase): BinaryFileResponse
    {
        $caminho = tempnam(sys_get_temp_dir(), 'boletim');
        $this->boletins->escrever($boletins, $caminho);

        return response()->download($caminho, Str::slug($nomeBase).'-'.date('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }

    public function responder(DocumentoPauta $doc, ?string $formato, string $nomeBase): BinaryFileResponse
    {
        $formato = in_array($formato, ['pdf', 'excel'], true) ? $formato : throw ValidationException::withMessages(['formato' => 'Formato inválido. Use pdf ou excel.']);

        $caminho = tempnam(sys_get_temp_dir(), 'pauta');
        $formato === 'pdf' ? $this->pdf->escrever($doc, $caminho) : $this->xlsx->escrever($doc, $caminho);

        return response()->download(
            $caminho,
            Str::slug($nomeBase).'-'.date('Y-m-d').'.'.($formato === 'pdf' ? 'pdf' : 'xlsx'),
            ['Content-Type' => $formato === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }
}
