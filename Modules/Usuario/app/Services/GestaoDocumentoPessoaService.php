<?php

namespace Modules\Usuario\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Usuario\Actions\AlternarEstadoDocumentoPessoaAction;
use Modules\Usuario\Actions\CriarDocumentoPessoaAction;
use Modules\Usuario\Actions\RemoverDocumentoPessoaAction;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GestaoDocumentoPessoaService
{
    public function __construct(
        private CriarDocumentoPessoaAction $criarAction,
        private RemoverDocumentoPessoaAction $removerAction,
        private AlternarEstadoDocumentoPessoaAction $alternarEstadoAction,
    ) {
    }

    public function listar(DadosPessoa $pessoa): Collection
    {
        return $pessoa->documentos()->with('tipoDocumento')->latest()->get();
    }

    public function adicionar(DadosPessoa $pessoa, DocumentoPessoaDTO $dto, UploadedFile $ficheiro): DocumentoPessoa
    {
        return $this->criarAction->executar($pessoa, $dto, $ficheiro);
    }

    public function remover(DocumentoPessoa $documento): void
    {
        $this->removerAction->executar($documento);
    }

    public function alternarEstado(DocumentoPessoa $documento): DocumentoPessoa
    {
        return $this->alternarEstadoAction->executar($documento);
    }

    public function download(DocumentoPessoa $documento): StreamedResponse
    {
        return Storage::disk('documentos')->download($documento->caminho, $documento->nome_original);
    }
}
