<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraNivel;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\RegraPadraoService;
use Modules\Core\Traits\LogService;
use Modules\Turma\Models\NivelAcademico;

/**
 * Um ano lectivo sem regras arranca já com elas: herda as do ano anterior (com as classes ligadas e o que a escola mudou)
 * ou, no primeiro ano, as regras por defeito, para ninguém ter de configurar o que é igual para todos. Só actua quando o
 * ano ainda não tem nenhuma; depois disso as regras são da escola e nunca se mexem sozinhas.
 */
class GarantirRegrasDoAnoAction
{
    use LogService;

    public function __construct(private RegraPadraoService $padrao)
    {
    }

    public function executar(int $anoLectivoId): void
    {
        if (AvaliacaoRegra::query()->where('ano_lectivo_id', $anoLectivoId)->exists() || AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $anoLectivoId)->exists()) {
            return;
        }

        $this->comLog('Criar as regras de avaliação do ano', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($anoLectivoId) {
            try {
                DB::transaction(function () use ($anoLectivoId) {
                    $anterior = $this->anoAnterior($anoLectivoId);

                    if ($anterior !== null) {
                        $this->herdar($anterior, $anoLectivoId);
                    } else {
                        $this->porDefeito($anoLectivoId);
                    }
                });
            } catch (UniqueConstraintViolationException) {
                // Outro pedido criou as regras do ano ao mesmo tempo (a restrição única das ligações impede duplicados): está feito.
            }
        });
    }

    /** O ano lectivo anterior mais recente que tenha regras. */
    private function anoAnterior(int $anoLectivoId): ?int
    {
        $destino = AnoLectivo::query()->findOrFail($anoLectivoId);

        $comRegras = AvaliacaoRegraNivel::query()->select('ano_lectivo_id')->distinct();

        return AnoLectivo::query()
            ->where('estabelecimento_id', $destino->estabelecimento_id)
            ->whereDate('data_fim', '<', $destino->data_inicio)
            ->whereIn('id', $comRegras)
            ->orderByDesc('data_fim')
            ->value('id');
    }

    private function herdar(int $anteriorId, int $destinoId): void
    {
        foreach (AvaliacaoRegra::query()->where('ano_lectivo_id', $anteriorId)->with(['niveis', 'disciplinasChave:disciplinas.id'])->get() as $regra) {
            $nova = AvaliacaoRegra::create([...$regra->only([
                'nome', 'escala_maxima', 'nota_minima', 'peso_mac', 'peso_pt', 'sigla_prova', 'peso_mt1', 'peso_mt2', 'peso_mt3', 'peso_mfd_exame', 'peso_exame',
                'recurso_max_disciplinas', 'casas_decimais', 'modo_arredondamento', 'tratamento_negativas', 'com_exame', 'com_recurso', 'exame_usa_mfd_arredondada',
            ]), 'ano_lectivo_id' => $destinoId]);
            $nova->disciplinasChave()->syncWithPivotValues($regra->disciplinasChave->pluck('id')->all(), ['tenant_id' => $nova->tenant_id]);

            foreach ($regra->niveis as $ligacao) {
                AvaliacaoRegraNivel::create(['ano_lectivo_id' => $destinoId, 'nivel_academico_id' => $ligacao->nivel_academico_id, 'regra_id' => $nova->id]);
            }
        }

        foreach (AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $anteriorId)->with('niveis')->get() as $regra) {
            $nova = AvaliacaoRegraQualitativa::create([...$regra->only(['nome', 'escala_id', 'progressao']), 'ano_lectivo_id' => $destinoId]);

            foreach ($regra->niveis as $ligacao) {
                AvaliacaoRegraNivel::create(['ano_lectivo_id' => $destinoId, 'nivel_academico_id' => $ligacao->nivel_academico_id, 'regra_qualitativa_id' => $nova->id]);
            }
        }
    }

    /**
     * Cria as regras por defeito que faltam e liga-lhes as classes que ainda não seguem nenhuma (as que não têm regra por
     * defeito, como a 10.ª em diante, ficam como estão). Não mexe em nenhuma regra nem ligação existente.
     *
     * @return int quantas classes ficaram ligadas
     */
    public function completarPorDefeito(int $anoLectivoId): int
    {
        return $this->comLog('Criar as regras por defeito em falta', $this->contextoDeArgumentos(func_get_args(), 'completarPorDefeito'), fn () => DB::transaction(fn () => $this->porDefeito($anoLectivoId)));
    }

    private function porDefeito(int $anoLectivoId): int
    {
        $semRegra = NivelAcademico::query()->get(['id', 'codigo'])
            ->reject(fn (NivelAcademico $n) => AvaliacaoRegraNivel::query()->where('ano_lectivo_id', $anoLectivoId)->where('nivel_academico_id', $n->id)->exists());
        $ligadas = 0;

        foreach ($this->padrao->regras() as $definicao) {
            // (Não usar `only()`: na colecção Eloquent filtra por chave primária, não por código.)
            $ids = $semRegra->filter(fn (NivelAcademico $n) => in_array($n->codigo, $definicao['codigos'], true))->pluck('id')->all();

            if ($ids === []) {
                continue;
            }

            $coluna = $definicao['qualitativa'] ? 'regra_qualitativa_id' : 'regra_id';
            $modelo = $definicao['qualitativa'] ? AvaliacaoRegraQualitativa::class : AvaliacaoRegra::class;
            $regra = $modelo::query()->where('ano_lectivo_id', $anoLectivoId)->where('nome', $definicao['nome'])->first();

            if ($regra === null && $definicao['qualitativa']) {
                $regra = AvaliacaoRegraQualitativa::create(['ano_lectivo_id' => $anoLectivoId, 'nome' => $definicao['nome'], 'escala_id' => null]);
            } elseif ($regra === null) {
                $regra = AvaliacaoRegra::create([
                    'ano_lectivo_id' => $anoLectivoId, 'nome' => $definicao['nome'], 'escala_maxima' => $definicao['escala_maxima'], 'nota_minima' => $definicao['nota_minima'],
                    'casas_decimais' => 2, 'modo_arredondamento' => ModoArredondamento::HALF_UP, 'tratamento_negativas' => $definicao['tratamento_negativas'],
                    'com_exame' => $definicao['com_exame'], 'com_recurso' => $definicao['com_recurso'], 'sigla_prova' => $definicao['sigla_prova'],
                ]);
            }

            foreach ($ids as $nivelId) {
                AvaliacaoRegraNivel::create(['ano_lectivo_id' => $anoLectivoId, 'nivel_academico_id' => $nivelId, $coluna => $regra->id]);
                $ligadas++;
            }
        }

        return $ligadas;
    }
}
