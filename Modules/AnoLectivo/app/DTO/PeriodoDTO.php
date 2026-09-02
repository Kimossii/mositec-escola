<?php

namespace Modules\AnoLectivo\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AnoLectivo\Enums\TipoPeriodo;

class PeriodoDTO
{
    public function __construct(
        public string $nome,
        public TipoPeriodo $tipo,
        public string $dataInicio,
        public string $dataFim,
        public ?int $numero = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            tipo: TipoPeriodo::from((int) $dados['tipo']),
            dataInicio: $dados['data_inicio'],
            dataFim: $dados['data_fim'],
            numero: isset($dados['numero']) ? (int) $dados['numero'] : null,
        );
    }
}
