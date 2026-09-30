<?php

namespace Modules\Usuario\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Actions\AlternarEstadoDocumentoPessoaAction;
use Modules\Usuario\Actions\CriarDocumentoPessoaAction;
use Modules\Usuario\Actions\RemoverDocumentoPessoaAction;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GestaoDocumentoPessoaService
{
    // Mesma allowlist da validação de upload (GuardarDocumentoPessoaRequest:
    // mimes:pdf,jpg,jpeg,png) — reverificada aqui a partir do conteúdo real
    // do ficheiro, nunca da coluna mime_type (metadado enviado pelo
    // cliente, não fiável) nem da extensão do nome guardado.
    private const MIME_TYPES_SEGUROS_PARA_VISUALIZACAO = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private CriarDocumentoPessoaAction $criarAction,
        private RemoverDocumentoPessoaAction $removerAction,
        private AlternarEstadoDocumentoPessoaAction $alternarEstadoAction,
    ) {
    }

    public function listar(DadosPessoa $pessoa): Collection
    {
        return $pessoa->documentos()->where('estado', Estado::ATIVO->value)->with('tipoDocumento')->latest()->get();
    }

    public function listarInativos(DadosPessoa $pessoa): Collection
    {
        return $pessoa->documentos()->where('estado', Estado::INATIVO->value)->with('tipoDocumento')->latest()->get();
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

    public function visualizar(DocumentoPessoa $documento): StreamedResponse
    {
        $disco = Storage::disk('documentos');
        $mimeReal = $disco->mimeType($documento->caminho);

        if (! in_array($mimeReal, self::MIME_TYPES_SEGUROS_PARA_VISUALIZACAO, true)) {
            return $this->download($documento);
        }

        return $disco->response($documento->caminho, $documento->nome_original, [
            'Content-Type' => $mimeReal,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function tiposDisponiveis(): Collection
    {
        return TipoDocumento::where('estado', Estado::ATIVO->value)->orderBy('nome')->get(['id', 'nome', 'slug']);
    }
}
