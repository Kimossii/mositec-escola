<?php

namespace Modules\Financeiro\DTO;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\Dinheiro;

class PlanoPropinaDTO
{
    /**
     * @param  list<array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}>  $alvos
     */
    public function __construct(
        public readonly string $nome,
        public readonly Periodicidade $periodicidade,
        public readonly int $intervalo_meses,
        public readonly Dinheiro $valor,
        public readonly CarbonImmutable $data_inicio,
        public readonly CarbonImmutable $data_fim,
        public readonly array $alvos,
        public readonly ?int $ano_lectivo_id = null,
        public readonly ?string $descricao = null,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            // Propina sempre mensal: a periodicidade e o intervalo deixaram de ser controlados pelo cliente.
            periodicidade: Periodicidade::MENSAL,
            intervalo_meses: 1,
            valor: Dinheiro::deDecimal((string) $dados['valor'], app(MoedaDoTenant::class)->atual()),
            data_inicio: CarbonImmutable::parse((string) $dados['data_inicio']),
            data_fim: CarbonImmutable::parse((string) $dados['data_fim']),
            alvos: AlvosDoPlano::paraGravar((array) ($dados['alvos'] ?? [])),
            ano_lectivo_id: isset($dados['ano_lectivo_id']) ? (int) $dados['ano_lectivo_id'] : null,
            descricao: ($dados['descricao'] ?? null) !== '' ? ($dados['descricao'] ?? null) : null,
        );
    }
}
