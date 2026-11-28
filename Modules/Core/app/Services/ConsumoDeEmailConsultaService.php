<?php

namespace Modules\Core\Services;

use Modules\Core\Comunicacao\Email\ConsumoDeEmail;
use Modules\Core\Licenciamento\Contracts\RegrasDeEmailDaLicenca;

/** Consumo do mês de emails opcionais da escola corrente, para os ecrãs (só leitura). */
class ConsumoDeEmailConsultaService
{
    public function __construct(private ConsumoDeEmail $consumo, private RegrasDeEmailDaLicenca $regras) {}

    /** @return array{mes: string, usado: int, limite: ?int, percentagem: ?int, estado: string} */
    public function doTenantActual(): array
    {
        $regras = $this->regras->doTenantActual();

        return self::resumo(ConsumoDeEmail::mesActual(), $this->consumo->doMes(), $regras->limiteMes, $regras->avisoPct);
    }

    /** @return array{mes: string, usado: int, limite: ?int, percentagem: ?int, estado: string} estado: normal | aviso | esgotado */
    public static function resumo(string $mes, int $usado, ?int $limite, int $avisoPct): array
    {
        $percentagem = $limite === null ? null : ($limite === 0 ? 100 : (int) min(100, floor($usado * 100 / $limite)));
        $estado = match (true) {
            $limite === null => 'normal',
            $usado >= $limite => 'esgotado',
            $usado * 100 >= $avisoPct * $limite => 'aviso',
            default => 'normal',
        };

        return ['mes' => $mes, 'usado' => $usado, 'limite' => $limite, 'percentagem' => $percentagem, 'estado' => $estado];
    }
}
