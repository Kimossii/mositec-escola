<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\Moeda;

/** Quanto de uma operação (crédito + dinheiro) vai para uma propina. */
final class LinhaDeDistribuicao
{
    public const AUTOMATICA = 'automatica';

    public const EXPLICITA = 'explicita';

    public function __construct(
        public readonly int $propinaId,
        public readonly Dinheiro $valor,
    ) {
        if ($propinaId <= 0 || $valor->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('Cada linha da distribuição indica uma propina e um valor maior que zero.');
        }
    }

    /**
     * @param  array<mixed>  $linhas
     * @return list<self>
     */
    public static function lista(array $linhas): array
    {
        $vistas = [];

        foreach ($linhas as $linha) {
            if (! $linha instanceof self) {
                throw new InvalidArgumentException('A distribuição é uma lista de ' . self::class . '.');
            }

            if (isset($vistas[$linha->propinaId])) {
                throw new InvalidArgumentException('Cada propina só pode aparecer uma vez na distribuição.');
            }

            $vistas[$linha->propinaId] = true;
        }

        return array_values($linhas);
    }

    /**
     * Linhas do pedido HTTP (`[{propina_id, valor}]`, valor decimal na moeda do tenant). O campo `modo_distribuicao`
     * decide: 'automatica' → null (o motor escolhe); 'explicita' → a lista, que PODE ser vazia (nada para propinas,
     * tudo crédito). Assim o que o resumo mostrou é exactamente o que se grava (D19), mesmo sem linhas.
     * Linhas mal formadas (não array, sem propina/valor, valor ilegível, repetidas) são erro de validação (422),
     * nunca TypeError/500, mesmo que o pedido HTTP as deixe passar.
     *
     * @return list<self>|null
     */
    public static function doPedido(string $modo, mixed $linhas, Moeda $moeda): ?array
    {
        if ($modo !== self::EXPLICITA) {
            return null;
        }

        if ($linhas !== null && ! is_array($linhas)) {
            throw ValidationException::withMessages(['distribuicao' => 'A distribuição tem de ser uma lista de propinas e valores.']);
        }

        $resultado = [];

        foreach (array_values($linhas ?? []) as $i => $linha) {
            if (! is_array($linha) || ! isset($linha['propina_id'], $linha['valor'])) {
                throw ValidationException::withMessages(["distribuicao.{$i}" => 'Indique a propina e o valor de cada linha da distribuição.']);
            }

            if (! is_scalar($linha['propina_id']) || ! is_scalar($linha['valor'])) {
                throw ValidationException::withMessages(["distribuicao.{$i}" => 'Indique a propina e o valor de cada linha da distribuição.']);
            }

            try {
                $resultado[] = new self((int) $linha['propina_id'], Dinheiro::deDecimal((string) $linha['valor'], $moeda));
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(["distribuicao.{$i}.valor" => $e->getMessage()]);
            }
        }

        try {
            return self::lista($resultado);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['distribuicao' => $e->getMessage()]);
        }
    }
}
