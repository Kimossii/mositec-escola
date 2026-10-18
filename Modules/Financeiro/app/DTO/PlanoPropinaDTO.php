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
        public string $nome,
        public Periodicidade $periodicidade,
        public int $intervalo_meses,
        public Dinheiro $valor,
        public CarbonImmutable $data_inicio,
        public CarbonImmutable $data_fim,
        public array $alvos,
        public ?int $ano_lectivo_id = null,
        public ?string $descricao = null,
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
