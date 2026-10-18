<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Financeiro\Models\Pagamento;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serve o comprovativo do pagamento a partir do disco privado (nunca por endereço público). O MIME é relido do
 * conteúdo real do ficheiro e restrito à allowlist; tudo o resto é servido como descarga. Um caminho fora do tenant
 * devolve 404.
 */
class ComprovativoService
{
    private const MIME_TYPES_SEGUROS = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public function visualizar(Pagamento $pagamento): StreamedResponse
    {
        if ($pagamento->comprovativo_path === null) {
            abort(404);
        }

        $disco = Storage::disk('privado');
        $caminho = $this->caminhoSeguro($pagamento);
        $mimeReal = $disco->mimeType($caminho);

        if (! in_array($mimeReal, self::MIME_TYPES_SEGUROS, true)) {
            return $disco->download($caminho, $pagamento->comprovativo_nome_original);
        }

        return $disco->response($caminho, $pagamento->comprovativo_nome_original, [
            'Content-Type' => $mimeReal,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** 404 se o caminho guardado não estiver sob o prefixo do tenant corrente. */
    private function caminhoSeguro(Pagamento $pagamento): string
    {
        try {
            return CaminhoTenant::garantir($pagamento->comprovativo_path);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }
}
