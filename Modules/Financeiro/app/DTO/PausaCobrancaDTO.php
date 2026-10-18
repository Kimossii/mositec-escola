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
        public int $ano_lectivo_id,
        public string $descricao,
        public CarbonImmutable $data_inicio,
        public CarbonImmutable $data_fim,
        public bool $cobra_propina,
        public array $meses,
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
