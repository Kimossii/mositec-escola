<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Financeiro\Models\Pagamento;

/**
 * Anexa o comprovativo a um pagamento que ainda não tem nenhum (spec Comprovativo). O ficheiro fica no disco privado
 * do tenant e o caminho é gravado no pagamento; se a gravação falhar, o ficheiro é apagado (não fica órfão). Só anexa
 * quando estiver vazio: nunca substitui, para o histórico não ser reescrito.
 */
class AnexarComprovativoAction
{
    public function executar(Pagamento $pagamento, UploadedFile $comprovativo): Pagamento
    {
        if ($pagamento->comprovativo_path !== null) {
            throw ValidationException::withMessages(['comprovativo' => 'Este pagamento já tem comprovativo.']);
        }

        $caminho = $comprovativo->store(CaminhoTenant::para('financeiro/comprovativos'), 'privado');

        try {
            $pagamento->forceFill([
                'comprovativo_path' => $caminho,
                'comprovativo_nome_original' => $comprovativo->getClientOriginalName(),
                'comprovativo_mime_type' => $comprovativo->getClientMimeType(),
            ])->save();
        } catch (\Throwable $e) {
            Storage::disk('privado')->delete($caminho);

            throw $e;
        }

        return $pagamento->fresh();
    }
}
