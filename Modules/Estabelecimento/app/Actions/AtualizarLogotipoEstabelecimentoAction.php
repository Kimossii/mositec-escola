<?php

namespace Modules\Estabelecimento\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarLogotipoEstabelecimentoAction
{
    public function executar(Estabelecimento $estabelecimento, UploadedFile $logotipo): Estabelecimento
    {
        if ($estabelecimento->logotipo_path) {
            Storage::disk('public')->delete($estabelecimento->logotipo_path);
        }

        $path = $logotipo->store('estabelecimento/logotipos', 'public');

        $estabelecimento->update(['logotipo_path' => $path]);

        return $estabelecimento;
    }
}
