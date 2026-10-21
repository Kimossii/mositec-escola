<?php

namespace Modules\Financeiro\Support;

use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\MomentoObrigatorio;

/**
 * Campos de Produto e Serviço além dos básicos: obrigatoriedade (e a quem se aplica) e, só nos produtos, stock e
 * variantes (tamanhos). Regras de validação e normalização partilhadas pelos pedidos de criar e atualizar.
 */
final class CamposDoCatalogo
{
    /** @return array<string, mixed> */
    public static function regras(bool $produto): array
    {
        $tenantId = app(TenantContext::class)->id();
        $doTenant = fn (string $tabela) => Rule::exists($tabela, 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId));

        $regras = [
            'obrigatorio' => ['sometimes', 'boolean'],
            'momento_obrigatorio' => ['nullable', 'required_if:obrigatorio,true,1', Rule::enum(MomentoObrigatorio::class)],
            'apenas_alunos_novos' => ['sometimes', 'boolean'],
            'alvos_niveis' => ['sometimes', 'array'],
            'alvos_niveis.*' => ['integer', $doTenant('niveis_academicos')],
            'alvos_cursos' => ['sometimes', 'array'],
            'alvos_cursos.*' => ['integer', $doTenant('cursos')],
        ];

        if ($produto) {
            $regras += [
                'controla_stock' => ['sometimes', 'boolean'],
                'stock_quantidade' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'tem_variantes' => ['sometimes', 'boolean'],
                'variantes' => ['sometimes', 'array', 'max:50'],
                'variantes.*.nome' => ['required', 'string', 'max:100', 'distinct'],
                'variantes.*.stock_quantidade' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            ];
        }

        return $regras;
    }

    /** @return array<string, string> */
    public static function mensagens(): array
    {
        return [
            'momento_obrigatorio.required_if' => 'Indique quando se torna obrigatório.',
            'variantes.*.nome.required' => 'Indique o nome de cada tamanho.',
            'variantes.*.nome.distinct' => 'Há tamanhos repetidos.',
            'stock_quantidade.min' => 'O stock não pode ser negativo.',
        ];
    }

    /**
     * @param  array<string, mixed>  $dados  dados validados
     * @return array{atributos: array<string, mixed>, niveis: list<int>, cursos: list<int>, variantes: list<array{nome: string, stock_quantidade: int}>}
     */
    public static function normalizar(array $dados, bool $produto): array
    {
        $obrigatorio = (bool) ($dados['obrigatorio'] ?? false);
        $atributos = [
            'obrigatorio' => $obrigatorio,
            'momento_obrigatorio' => $obrigatorio ? (int) $dados['momento_obrigatorio'] : null,
            'apenas_alunos_novos' => $obrigatorio && (bool) ($dados['apenas_alunos_novos'] ?? false),
        ];
        $variantes = [];

        if ($produto) {
            $controla = (bool) ($dados['controla_stock'] ?? false);
            $temVariantes = (bool) ($dados['tem_variantes'] ?? false);
            $atributos += [
                'controla_stock' => $controla,
                'tem_variantes' => $temVariantes,
                // Com variantes o stock vive em cada uma; sem stock controlado não há quantidade.
                'stock_quantidade' => $controla && ! $temVariantes ? (int) ($dados['stock_quantidade'] ?? 0) : 0,
            ];

            if ($temVariantes) {
                foreach ($dados['variantes'] ?? [] as $variante) {
                    $variantes[] = ['nome' => trim($variante['nome']), 'stock_quantidade' => $controla ? (int) ($variante['stock_quantidade'] ?? 0) : 0];
                }
            }
        }

        return [
            'atributos' => $atributos,
            'niveis' => $obrigatorio ? array_values(array_unique(array_map('intval', $dados['alvos_niveis'] ?? []))) : [],
            'cursos' => $obrigatorio ? array_values(array_unique(array_map('intval', $dados['alvos_cursos'] ?? []))) : [],
            'variantes' => $variantes,
        ];
    }
}
