<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Core\Traits\AlternaEstado;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;

class AlternarEstadoDocumentoPessoaAction
{
    use AlternaEstado;

    public function executar(DocumentoPessoa $documento): DocumentoPessoa
    {
        return DB::transaction(function () use ($documento) {
            DadosPessoa::whereKey($documento->dados_pessoa_id)->lockForUpdate()->first();

            $vaiFicarAtivo = $documento->estado !== Estado::ATIVO->value;

            if ($vaiFicarAtivo) {
                DocumentoPessoa::where('dados_pessoa_id', $documento->dados_pessoa_id)
                    ->where('tipo_documento_id', $documento->tipo_documento_id)
                    ->where('id', '!=', $documento->id)
                    ->where('estado', Estado::ATIVO->value)
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (DocumentoPessoa $outro) => $outro->update(['estado' => Estado::INATIVO->value]));
            }

            return $this->alternarEstado($documento);
        });
    }
}
