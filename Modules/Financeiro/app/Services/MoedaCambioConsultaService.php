<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Support\Moeda;

class MoedaCambioConsultaService
{
    public function __construct(
        private MoedaDoTenant $moedaDoTenant,
        private CambioDoDia $cambioDoDia,
    ) {
    }

    /**
     * @return array{moeda: string, cambio_manual: bool, pode_alterar_moeda: bool}
     */
    public function configuracao(): array
    {
        $configuracao = $this->moedaDoTenant->configuracao();

        return [
            'moeda' => $configuracao->moeda,
            'cambio_manual' => $configuracao->cambio_manual,
            'pode_alterar_moeda' => $this->moedaDoTenant->podeAlterar(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function moeda(): array
    {
        return $this->moedaDoTenant->atual()->paraFrontend();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function moedas(): array
    {
        return Moeda::opcoes();
    }

    /**
     * @return array{taxa: string, data: ?string, origem: string}|null
     */
    public function cambioVigente(): ?array
    {
        $resolvido = $this->cambioDoDia->resolver(now());

        if ($resolvido === null) {
            return null;
        }

        return ['taxa' => $resolvido->taxa->formatar(), 'data' => $resolvido->data, 'origem' => $resolvido->origem];
    }

    /**
     * Câmbios da escola na moeda actual, do mais recente para o mais antigo.
     */
    public function historico(int $porPagina = 10): LengthAwarePaginator
    {
        return Cambio::query()
            ->where('moeda_cotada', $this->moedaDoTenant->atual()->codigo)
            ->orderByDesc('data')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (Cambio $cambio) => [
                'id' => $cambio->id,
                'data' => $cambio->data->toDateString(),
                'taxa' => $cambio->taxa,
                'taxa_formatada' => $cambio->taxaCambio()->formatar(),
            ]);
    }
}
