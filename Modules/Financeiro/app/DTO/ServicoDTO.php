<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\CamposDoCatalogo;
use Modules\Financeiro\Support\Dinheiro;

class ServicoDTO
{
    public function __construct(
        public readonly string $nome,
        public readonly Dinheiro $preco,
        public readonly ?string $codigo = null,
        public readonly ?string $descricao = null,
        /** @var array{atributos: array<string, mixed>, niveis: list<int>, cursos: list<int>, variantes: list<array>} */
        public readonly array $extras = ['atributos' => [], 'niveis' => [], 'cursos' => [], 'variantes' => []],
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            preco: Dinheiro::deDecimal((string) $dados['preco'], app(MoedaDoTenant::class)->atual()),
            codigo: ($dados['codigo'] ?? null) !== '' ? ($dados['codigo'] ?? null) : null,
            descricao: ($dados['descricao'] ?? null) !== '' ? ($dados['descricao'] ?? null) : null,
            extras: CamposDoCatalogo::normalizar($dados, false),
        );
    }
}
