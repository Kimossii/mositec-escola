<?php

namespace Modules\Core\Comunicacao\Email;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Licenciamento\Contracts\RegrasDeEmailDaLicenca;
use Modules\Core\Models\ConsumoEmailMensal;
use Modules\Core\Tenancy\TenantContext;

/**
 * Quota mensal de emails OPCIONAIS da escola corrente (regras vêm da subscrição). Os essenciais nunca passam por aqui.
 * O incremento corre numa transacção com a linha do mês bloqueada: duas tentativas em simultâneo não ultrapassam a quota.
 */
class ConsumoDeEmail
{
    public function __construct(private TenantContext $contexto, private RegrasDeEmailDaLicenca $regras) {}

    public function tentarConsumir(): ResultadoConsumo
    {
        if (! $this->contexto->temTenant()) {
            return ResultadoConsumo::PERMITIDO;
        }

        $regras = $this->regras->doTenantActual();

        return DB::transaction(function () use ($regras) {
            $linha = $this->linhaDoMes(self::mesActual())->lockForUpdate()->first()
                ?? $this->criar(self::mesActual());
            $limite = $regras->limiteMes;

            if ($limite !== null && $regras->bloqueia && $linha->opcionais >= $limite) {
                return ResultadoConsumo::BLOQUEADO;
            }

            $linha->opcionais++;
            $resultado = ResultadoConsumo::PERMITIDO;

            if ($limite !== null) {
                if ($linha->opcionais === $limite) {
                    $linha->aviso_enviado = true; // o aviso de «a esgotar» já não faz sentido depois de esgotada
                    $resultado = ResultadoConsumo::PERMITIDO_NO_LIMITE;
                } elseif ($linha->opcionais < $limite && ! $linha->aviso_enviado && $linha->opcionais * 100 >= $regras->avisoPct * $limite) {
                    $linha->aviso_enviado = true;
                    $resultado = ResultadoConsumo::PERMITIDO_NO_AVISO;
                }
            }

            $linha->save();

            return $resultado;
        });
    }

    /** Emails opcionais já enviados no mês (AAAA-MM; por omissão o actual). */
    public function doMes(?string $mes = null): int
    {
        return (int) ($this->linhaDoMes($mes ?? self::mesActual())->value('opcionais') ?? 0);
    }

    /** true só na primeira vez no mês: quem chama avisa então o administrador (uma vez por mês). */
    public function marcarBloqueioAvisado(): bool
    {
        $linha = $this->linhaDoMes(self::mesActual())->lockForUpdate()->first();

        if ($linha === null || $linha->bloqueio_avisado) {
            return false;
        }

        $linha->update(['bloqueio_avisado' => true]);

        return true;
    }

    public static function mesActual(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m');
    }

    private function linhaDoMes(string $mes)
    {
        return ConsumoEmailMensal::query()->where('mes', $mes);
    }

    private function criar(string $mes): ConsumoEmailMensal
    {
        return ConsumoEmailMensal::query()->firstOrCreate(['mes' => $mes], ['opcionais' => 0]);
    }
}
