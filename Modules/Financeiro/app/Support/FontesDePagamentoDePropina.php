<?php

namespace Modules\Financeiro\Support;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Financeiro\Contracts\FonteDePagamentoDePropina;
use Modules\Financeiro\Models\Propina;

class FontesDePagamentoDePropina
{
    public const ETIQUETA = 'financeiro.fontes-pagamento-propina';

    public function __construct(private Container $container)
    {
    }

    /**
     * Contribuições de todas as fontes, por data crescente (ordenação estável: empates mantêm a ordem
     * devolvida pelas fontes). Sem fontes registadas: lista vazia.
     *
     * @return list<ContribuicaoDePagamento>
     */
    public function contribuicoes(Propina $propina): array
    {
        $todas = [];

        foreach ($this->container->tagged(self::ETIQUETA) as $fonte) {
            if (! $fonte instanceof FonteDePagamentoDePropina) {
                throw new LogicException('O serviço registado na etiqueta ' . self::ETIQUETA . ' (' . get_debug_type($fonte) . ') tem de implementar ' . FonteDePagamentoDePropina::class . '.');
            }

            $devolvidas = $fonte->contribuicoes($propina);

            if (! is_array($devolvidas)) {
                throw new LogicException('A fonte ' . $fonte::class . ' tem de devolver uma lista de ' . ContribuicaoDePagamento::class . '.');
            }

            foreach ($devolvidas as $contribuicao) {
                if (! $contribuicao instanceof ContribuicaoDePagamento) {
                    throw new LogicException('A fonte ' . $fonte::class . ' devolveu ' . get_debug_type($contribuicao) . ' em vez de ' . ContribuicaoDePagamento::class . '.');
                }

                $todas[] = $contribuicao;
            }
        }

        usort($todas, fn (ContribuicaoDePagamento $a, ContribuicaoDePagamento $b) => $a->data->toDateString() <=> $b->data->toDateString());

        return $todas;
    }
}
