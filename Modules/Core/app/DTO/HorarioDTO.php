<?php

namespace Modules\Core\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Enums\Estado;

class HorarioDTO
{
    public function __construct(
        public readonly string $nome,
        public readonly string $horaInicio,
        public readonly string $horaFim,
        public readonly Estado $estado = Estado::ATIVO,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            horaInicio: $dados['hora_inicio'],
            horaFim: $dados['hora_fim'],
            estado: isset($dados['estado']) ? Estado::from((int) $dados['estado']) : Estado::ATIVO,
        );
    }
}
