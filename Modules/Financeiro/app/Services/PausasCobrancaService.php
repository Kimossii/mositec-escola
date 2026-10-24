<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\DTO\PausaCobrancaDTO;
use Modules\Financeiro\Models\PausaCobranca;
use Modules\Financeiro\Models\PausaCobrancaMes;
use Modules\Financeiro\Support\MesesDePausa;

/**
 * Gestão das pausas de cobrança de um ano lectivo. Criar/atualizar uma pausa «não cobra» resolve os meses
 * tocados (inteiros → isentos; parciais → escolha do utilizador) e, na mesma transacção, cancela as propinas
 * dos meses que ficaram isentos. Eliminar/voltar a «cobra» deixa simplesmente de isentar: as propinas voltam
 * pelo fluxo normal de regeneração.
 */
class PausasCobrancaService
{
    public function __construct(
        private CancelarPropinasDeMesesIsentos $cancelar,
    ) {
    }

    /**
     * @return array{canceladas: int, mantidas: int}
     */
    public function criar(PausaCobrancaDTO $dto, ?int $utilizadorId): array
    {
        return DB::transaction(function () use ($dto, $utilizadorId) {
            $pausa = PausaCobranca::create([
                'ano_lectivo_id' => $dto->ano_lectivo_id,
                'descricao' => $dto->descricao,
                'data_inicio' => $dto->data_inicio,
                'data_fim' => $dto->data_fim,
                'cobra_propina' => $dto->cobra_propina,
            ]);

            $pausa->substituirMeses($this->mesesParaGravar($dto));

            return $this->reconciliar($pausa, $utilizadorId);
        });
    }

    /**
     * @return array{canceladas: int, mantidas: int}
     */
    public function atualizar(PausaCobranca $pausa, PausaCobrancaDTO $dto, ?int $utilizadorId): array
    {
        return DB::transaction(function () use ($pausa, $dto, $utilizadorId) {
            $pausa->update([
                'descricao' => $dto->descricao,
                'data_inicio' => $dto->data_inicio,
                'data_fim' => $dto->data_fim,
                'cobra_propina' => $dto->cobra_propina,
            ]);

            $pausa->substituirMeses($this->mesesParaGravar($dto));

            return $this->reconciliar($pausa, $utilizadorId);
        });
    }

    public function eliminar(PausaCobranca $pausa): void
    {
        DB::transaction(function () use ($pausa) {
            $pausa->meses()->delete();
            $pausa->delete();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listar(?int $anoLectivoId = null): array
    {
        return PausaCobranca::query()
            ->with('meses')
            ->when($anoLectivoId !== null, fn ($query) => $query->where('ano_lectivo_id', $anoLectivoId))
            ->orderBy('data_inicio')
            ->get()
            ->map(fn (PausaCobranca $pausa) => [
                'id' => $pausa->id,
                'ano_lectivo_id' => $pausa->ano_lectivo_id,
                'descricao' => $pausa->descricao,
                'data_inicio' => $pausa->data_inicio->toDateString(),
                'data_fim' => $pausa->data_fim->toDateString(),
                'cobra_propina' => $pausa->cobra_propina,
                'meses' => $pausa->meses->map(fn (PausaCobrancaMes $mes) => [
                    'ano' => $mes->ano,
                    'mes' => $mes->mes,
                    'isento' => $mes->isento,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Meses isentos de um ano lectivo, como ordinais (ano × 12 + mês), para a geração os saltar.
     *
     * @return list<int>
     */
    public function mesesIsentos(int $anoLectivoId): array
    {
        return PausaCobrancaMes::query()
            ->where('isento', true)
            ->whereHas('pausa', fn ($query) => $query
                ->where('ano_lectivo_id', $anoLectivoId)
                ->where('cobra_propina', false))
            ->get(['ano', 'mes'])
            ->map(fn (PausaCobrancaMes $mes) => $mes->ano * 12 + $mes->mes)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Meses a gravar: para pausas «cobra» nenhum; para «não cobra», os tocados com isenção automática nos
     * inteiros e a escolha do utilizador nos parciais.
     *
     * @return list<array{ano: int, mes: int, isento: bool}>
     */
    private function mesesParaGravar(PausaCobrancaDTO $dto): array
    {
        if ($dto->cobra_propina) {
            return [];
        }

        $escolhas = [];

        foreach ($dto->meses as $mes) {
            $escolhas["{$mes['ano']}-{$mes['mes']}"] = $mes['isento'];
        }

        return array_map(fn (array $tocado) => [
            'ano' => $tocado['ano'],
            'mes' => $tocado['mes'],
            'isento' => $tocado['completo'] ? true : ($escolhas["{$tocado['ano']}-{$tocado['mes']}"] ?? false),
        ], MesesDePausa::tocados($dto->data_inicio, $dto->data_fim));
    }

    /**
     * @return array{canceladas: int, mantidas: int}
     */
    private function reconciliar(PausaCobranca $pausa, ?int $utilizadorId): array
    {
        if ($pausa->cobra_propina) {
            return ['canceladas' => 0, 'mantidas' => 0];
        }

        $mesesIsentos = $pausa->meses()
            ->where('isento', true)
            ->get(['ano', 'mes'])
            ->map(fn (PausaCobrancaMes $mes) => ['ano' => $mes->ano, 'mes' => $mes->mes])
            ->all();

        $resultado = $this->cancelar->executar((int) $pausa->ano_lectivo_id, $mesesIsentos, $utilizadorId);

        return ['canceladas' => count($resultado['canceladas']), 'mantidas' => count($resultado['mantidas'])];
    }
}
