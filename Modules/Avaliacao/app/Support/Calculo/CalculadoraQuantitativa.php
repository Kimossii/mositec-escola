<?php

namespace Modules\Avaliacao\Support\Calculo;

use InvalidArgumentException;
use Modules\Avaliacao\Enums\ModoArredondamento;

/**
 * Única fonte das fórmulas de avaliação quantitativa (MAC → MT → MFD). Pura: sem BD, sem models, sem `float`.
 * Valores decimais são `string` (bcmath, escala interna 10); `null` significa "indisponível", nunca zero.
 * Os pesos vêm em percentagem. O arredondamento só existe aqui para a apresentação e para a decisão sobre o
 * valor impresso; nenhuma fórmula arredonda a meio.
 */
class CalculadoraQuantitativa
{
    private const ESCALA = 10;

    /** MAC: soma das notas consideradas ÷ quantidade de notas consideradas. */
    public function media(array $valores): ?string
    {
        if ($valores === []) {
            return null;
        }

        $soma = '0';
        foreach ($valores as $valor) {
            $soma = bcadd($soma, (string) $valor, self::ESCALA);
        }

        return bcdiv($soma, (string) count($valores), self::ESCALA);
    }

    /** MT = (MAC × pesoMac + PT × pesoPt) ÷ 100. */
    public function mt(?string $mac, ?string $pt, string $pesoMac, string $pesoPt): ?string
    {
        $this->exigirSoma100([$pesoMac, $pesoPt]);

        if ($mac === null || $pt === null) {
            return null;
        }

        $soma = bcadd(bcmul($mac, $pesoMac, self::ESCALA), bcmul($pt, $pesoPt, self::ESCALA), self::ESCALA);

        return bcdiv($soma, '100', self::ESCALA);
    }

    /**
     * MFD das três médias trimestrais; `null` se faltar qualquer uma. Sem `$pesos` os trimestres pesam o mesmo.
     *
     * @param  array<int, ?string>  $mts
     * @param  array<int, string>|null  $pesos  percentagens, somam 100
     */
    public function mfd(array $mts, ?array $pesos = null): ?string
    {
        if (count($mts) !== 3) {
            return null;
        }

        foreach ($mts as $mt) {
            if ($mt === null) {
                return null;
            }
        }

        $mts = array_values($mts);

        if ($pesos === null) {
            return bcdiv(bcadd(bcadd($mts[0], $mts[1], self::ESCALA), $mts[2], self::ESCALA), '3', self::ESCALA);
        }

        $pesos = array_values($pesos);
        $this->exigirSoma100($pesos);

        $soma = '0';
        foreach ($mts as $i => $mt) {
            $soma = bcadd($soma, bcmul($mt, $pesos[$i], self::ESCALA), self::ESCALA);
        }

        return bcdiv($soma, '100', self::ESCALA);
    }

    public function arredondar(string $valor, int $casas, ModoArredondamento $modo): string
    {
        $fator = bcpow('10', (string) $casas, 0);
        $escalado = bcmul($valor, $fator, self::ESCALA);
        $inteiro = bcadd($escalado, '0', 0);

        if ($modo === ModoArredondamento::TRUNCAR) {
            return bcdiv($inteiro, $fator, $casas);
        }

        $resto = bcsub($escalado, $inteiro, self::ESCALA);
        $comparacao = bccomp($resto, '0.5', self::ESCALA);
        $sobe = $comparacao > 0
            || ($comparacao === 0 && ($modo === ModoArredondamento::HALF_UP || (int) bcmod($inteiro, '2') === 1));

        return bcdiv($sobe ? bcadd($inteiro, '1', 0) : $inteiro, $fator, $casas);
    }

    /** O valor recebido já é o arredondado, igual ao impresso na pauta. */
    public function aprovadoNaDisciplina(string $valorArredondado, string $notaMinima): bool
    {
        return bccomp($valorArredondado, $notaMinima, self::ESCALA) >= 0;
    }

    /** @param  array<int, string>  $pesos */
    private function exigirSoma100(array $pesos): void
    {
        $soma = '0';
        foreach ($pesos as $peso) {
            $soma = bcadd($soma, $peso, 4);
        }

        if (bccomp($soma, '100', 4) !== 0) {
            throw new InvalidArgumentException('Os pesos têm de somar 100%.');
        }
    }
}
