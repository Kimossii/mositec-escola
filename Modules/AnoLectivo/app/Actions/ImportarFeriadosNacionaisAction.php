<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Support\FeriadosNacionaisDeAngola;
use Modules\Core\Traits\LogService;

/**
 * Acrescenta ao calendário do ano lectivo os feriados nacionais que caem dentro dele (o ano pode atravessar dois anos
 * civis). Idempotente: um dia já coberto por um feriado ou por férias do calendário conta como existente e não se
 * duplica, por isso pode importar-se de novo sem estragar o que a escola já ajustou.
 */
class ImportarFeriadosNacionaisAction
{
    use LogService;

    public function __construct(private FeriadosNacionaisDeAngola $feriados)
    {
    }

    /** @return array{criados: int, existentes: int} */
    public function executar(AnoLectivo $anoLectivo): array
    {
        return $this->comLog('Importar feriados nacionais', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($anoLectivo));
    }

    /** @return array{criados: int, existentes: int} */
    private function executarSemRegisto(AnoLectivo $anoLectivo): array
    {
        $inicio = $anoLectivo->data_inicio->toDateString();
        $fim = $anoLectivo->data_fim->toDateString();

        $cobertos = EventoCalendario::query()
            ->where('ano_lectivo_id', $anoLectivo->id)
            ->whereIn('tipo', [TipoEventoCalendario::FERIADO->value, TipoEventoCalendario::FERIAS->value])
            ->get(['data_inicio', 'data_fim']);

        $criados = 0;
        $existentes = 0;

        for ($ano = (int) $anoLectivo->data_inicio->format('Y'); $ano <= (int) $anoLectivo->data_fim->format('Y'); $ano++) {
            foreach ($this->feriados->doAno($ano) as $feriado) {
                if ($feriado['data'] < $inicio || $feriado['data'] > $fim) {
                    continue;
                }

                if ($cobertos->contains(fn ($e) => $e->data_inicio->toDateString() <= $feriado['data'] && $e->data_fim->toDateString() >= $feriado['data'])) {
                    $existentes++;

                    continue;
                }

                $anoLectivo->eventosCalendario()->create([
                    'titulo' => $feriado['nome'],
                    'tipo' => TipoEventoCalendario::FERIADO->value,
                    'data_inicio' => $feriado['data'],
                    'data_fim' => $feriado['data'],
                    'dia_inteiro' => true,
                ]);
                $criados++;
            }
        }

        return ['criados' => $criados, 'existentes' => $existentes];
    }
}
