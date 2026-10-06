<?php

namespace Modules\Usuario\DTO;

use Modules\Usuario\Http\Requests\GuardarDocumentoPessoaRequest;

class DocumentoPessoaDTO
{
    public function __construct(
        public int $tipo_documento_id,
        public ?string $numero_documento,
        public ?string $data_emissao,
        public ?string $data_validade,
        public ?string $observacoes,
    ) {}

    public static function fromRequest(GuardarDocumentoPessoaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            tipo_documento_id: (int) $dados['tipo_documento_id'],
            numero_documento: $dados['numero_documento'] ?? null,
            data_emissao: $dados['data_emissao'] ?? null,
            data_validade: $dados['data_validade'] ?? null,
            observacoes: $dados['observacoes'] ?? null,
        );
    }
}
