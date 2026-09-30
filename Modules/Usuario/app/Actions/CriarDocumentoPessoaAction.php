<?php

namespace Modules\Usuario\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;

class CriarDocumentoPessoaAction
{
    public function executar(DadosPessoa $pessoa, DocumentoPessoaDTO $dto, UploadedFile $ficheiro): DocumentoPessoa
    {
        return DB::transaction(function () use ($pessoa, $dto, $ficheiro) {
            DadosPessoa::whereKey($pessoa->id)->lockForUpdate()->first();

            DocumentoPessoa::where('dados_pessoa_id', $pessoa->id)
                ->where('tipo_documento_id', $dto->tipo_documento_id)
                ->where('estado', Estado::ATIVO->value)
                ->lockForUpdate()
                ->get()
                ->each(fn (DocumentoPessoa $anterior) => $anterior->update(['estado' => Estado::INATIVO->value]));

            $caminho = $ficheiro->store('documentos-pessoas/' . $pessoa->id, 'documentos');

            return DocumentoPessoa::create([
                'dados_pessoa_id' => $pessoa->id,
                'tipo_documento_id' => $dto->tipo_documento_id,
                'numero_documento' => $dto->numero_documento,
                'data_emissao' => $dto->data_emissao,
                'data_validade' => $dto->data_validade,
                'observacoes' => $dto->observacoes,
                'nome_original' => $ficheiro->getClientOriginalName(),
                'caminho' => $caminho,
                'mime_type' => $ficheiro->getClientMimeType(),
                'tamanho' => $ficheiro->getSize(),
                'estado' => Estado::ATIVO->value,
            ]);
        });
    }
}
