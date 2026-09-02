<?php

namespace Modules\AnoLectivo\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;

class EventoCalendarioDTO
{
    public function __construct(
        public string $titulo,
        public TipoEventoCalendario $tipo,
        public string $dataInicio,
        public string $dataFim,
        public ?string $descricao = null,
        public bool $diaInteiro = true,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            titulo: $dados['titulo'],
            tipo: TipoEventoCalendario::from((int) $dados['tipo']),
            dataInicio: $dados['data_inicio'],
            dataFim: $dados['data_fim'],
            descricao: $dados['descricao'] ?? null,
            diaInteiro: (bool) ($dados['dia_inteiro'] ?? true),
        );
    }
}
