<?php

namespace Modules\Infraestrutura\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;

class SalaDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public TipoSala $tipo,
        public ?int $capacidade = null,
        public ?string $localizacao = null,
        public ?string $observacoes = null,
        public EstadoSala $estado = EstadoSala::ATIVA,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            tipo: TipoSala::from((int) $dados['tipo']),
            capacidade: isset($dados['capacidade']) ? (int) $dados['capacidade'] : null,
            localizacao: $dados['localizacao'] ?? null,
            observacoes: $dados['observacoes'] ?? null,
            estado: isset($dados['estado']) ? EstadoSala::from((int) $dados['estado']) : EstadoSala::ATIVA,
        );
    }
}
