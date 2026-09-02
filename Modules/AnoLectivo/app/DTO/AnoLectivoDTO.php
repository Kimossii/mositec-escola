<?php

namespace Modules\AnoLectivo\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;

class AnoLectivoDTO
{
    public function __construct(
        public string $nome,
        public string $dataInicio,
        public string $dataFim,
        public EstadoAnoLectivo $estado = EstadoAnoLectivo::PLANEADO,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            dataInicio: $dados['data_inicio'],
            dataFim: $dados['data_fim'],
            estado: isset($dados['estado']) ? EstadoAnoLectivo::from((int) $dados['estado']) : EstadoAnoLectivo::PLANEADO,
        );
    }
}
