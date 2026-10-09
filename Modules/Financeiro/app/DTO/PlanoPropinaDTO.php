<?php

namespace Modules\Financeiro\DTO;

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
        public int $mes_inicio,
        public int $mes_fim,
        public array $alvos,
        public ?int $ano_lectivo_id = null,
        public ?string $descricao = null,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();
        $periodicidade = Periodicidade::from((int) $dados['periodicidade']);

        return new self(
            nome: $dados['nome'],
            periodicidade: $periodicidade,
            intervalo_meses: $periodicidade->meses() ?? (int) $dados['intervalo_meses'],
            valor: Dinheiro::deDecimal((string) $dados['valor'], app(MoedaDoTenant::class)->atual()),
            mes_inicio: (int) $dados['mes_inicio'],
            mes_fim: (int) $dados['mes_fim'],
            alvos: AlvosDoPlano::paraGravar((array) ($dados['alvos'] ?? [])),
            ano_lectivo_id: isset($dados['ano_lectivo_id']) ? (int) $dados['ano_lectivo_id'] : null,
            descricao: ($dados['descricao'] ?? null) !== '' ? ($dados['descricao'] ?? null) : null,
        );
    }
}
