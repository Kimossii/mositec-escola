<?php

namespace Modules\Estabelecimento\DTO;

use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Http\Requests\AtualizarDadosRequest;

class EstabelecimentoDTO
{
    public function __construct(
        public string $nome,
        public TipoEstabelecimentoEnum $tipo,
        public ?string $nome_abreviado = null,
        public ?string $nif = null,
        public ?string $codigo_mined = null,
        public ?string $numero_alvara = null,
        public ?string $email = null,
        public ?string $telefone = null,
        public ?string $telefone_alternativo = null,
        public ?string $website = null,
        public ?string $endereco = null,
        public ?string $caixa_postal = null,
        public ?string $municipio = null,
        public ?string $provincia = null,
        public ?string $responsavel_nome = null,
        public ?string $responsavel_cargo = null,
        public ?int $ano_fundacao = null,
        public ?string $observacoes = null,
    ) {
    }

    public static function fromRequest(AtualizarDadosRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            tipo: TipoEstabelecimentoEnum::from((int) $dados['tipo']),
            nome_abreviado: $dados['nome_abreviado'] ?? null,
            nif: $dados['nif'] ?? null,
            codigo_mined: $dados['codigo_mined'] ?? null,
            numero_alvara: $dados['numero_alvara'] ?? null,
            email: $dados['email'] ?? null,
            telefone: $dados['telefone'] ?? null,
            telefone_alternativo: $dados['telefone_alternativo'] ?? null,
            website: $dados['website'] ?? null,
            endereco: $dados['endereco'] ?? null,
            caixa_postal: $dados['caixa_postal'] ?? null,
            municipio: $dados['municipio'] ?? null,
            provincia: $dados['provincia'] ?? null,
            responsavel_nome: $dados['responsavel_nome'] ?? null,
            responsavel_cargo: $dados['responsavel_cargo'] ?? null,
            ano_fundacao: isset($dados['ano_fundacao']) ? (int) $dados['ano_fundacao'] : null,
            observacoes: $dados['observacoes'] ?? null,
        );
    }
}
