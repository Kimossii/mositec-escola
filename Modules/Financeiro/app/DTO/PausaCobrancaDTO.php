<?php

namespace Modules\Financeiro\DTO;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class PausaCobrancaDTO
{
    /**
     * @param  list<array{ano: int, mes: int, isento: bool}>  $meses  escolhas do utilizador (só meses parciais)
     */
    public function __construct(
        public readonly int $ano_lectivo_id,
        public readonly string $descricao,
        public readonly CarbonImmutable $data_inicio,
        public readonly CarbonImmutable $data_fim,
        public readonly bool $cobra_propina,
        public readonly array $meses,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            ano_lectivo_id: (int) $dados['ano_lectivo_id'],
            descricao: (string) $dados['descricao'],
            data_inicio: CarbonImmutable::parse((string) $dados['data_inicio']),
            data_fim: CarbonImmutable::parse((string) $dados['data_fim']),
            cobra_propina: (bool) $dados['cobra_propina'],
            meses: array_map(
                fn (array $mes) => ['ano' => (int) $mes['ano'], 'mes' => (int) $mes['mes'], 'isento' => (bool) $mes['isento']],
                (array) ($dados['meses'] ?? []),
            ),
        );
    }
}
