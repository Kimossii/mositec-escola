<?php

namespace Modules\Estabelecimento\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarLogotipoEstabelecimentoAction
{
    public function executar(Estabelecimento $estabelecimento, UploadedFile $logotipo): Estabelecimento
    {
        if ($estabelecimento->logotipo_path) {
            // Só apaga se o caminho guardado pertencer mesmo ao tenant corrente.
            try {
                Storage::disk('public')->delete(CaminhoTenant::garantir($estabelecimento->logotipo_path));
            } catch (InvalidArgumentException) {
                // Caminho fora do tenant ou adulterado: não se apaga nada.
            }
        }

        $path = $logotipo->store(CaminhoTenant::para('estabelecimento/logotipos'), 'public');

        $estabelecimento->update(['logotipo_path' => $path]);

        return $estabelecimento;
    }
}
