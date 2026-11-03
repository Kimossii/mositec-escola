<?php

namespace Modules\Avaliacao\Services;

use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoDocumentoConfig;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;

/** Leitura da página de configuração: regras por classe, prazos por trimestre e tipo, e excepções. Só consulta. */
class ConfiguracaoAvaliacaoService
{
    public function __construct(private RegraPadraoService $padrao, private CabecalhoDocumentoService $cabecalho)
    {
    }

    /** @return array<string, mixed> */
    public function pagina(?int $anoLectivoId = null): array
    {
        $anos = AnoLectivo::query()->orderByDesc('data_inicio')->get();
        $ano = $anos->firstWhere('id', $anoLectivoId) ?? $anos->firstWhere('estado', EstadoAnoLectivo::ATIVO) ?? $anos->first();

        if ($ano === null) {
            return ['anos' => [], 'ano_id' => null, 'classes' => [], 'periodos' => [], 'modos' => $this->modos(), 'tipos' => $this->tipos(), 'escalas' => $this->escalas(), ...$this->documentos()];
        }

        $regras = AvaliacaoRegra::query()->where('ano_lectivo_id', $ano->id)->get()->keyBy('nivel_academico_id');
        $prazos = AvaliacaoPrazo::query()->whereIn('regra_id', $regras->pluck('id'))->with('excepcoes')->get();
        $regrasQ = AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $ano->id)->get()->keyBy('nivel_academico_id');
        $turmas = Turma::query()->where('ano_lectivo_id', $ano->id)->with(['turno:id,nome', 'anoLectivo:id,nome'])->get()->groupBy('nivel_academico_id');

        $classes = NivelAcademico::query()->orderBy('ordem')->get()->map(function (NivelAcademico $nivel) use ($regras, $regrasQ, $prazos, $turmas) {
            $regra = $regras->get($nivel->id);
            $sugestao = $this->padrao->paraNivel((string) $nivel->codigo);

            return [
                'nivel_id' => $nivel->id,
                'codigo' => $nivel->codigo,
                'nome' => $nivel->nome,
                'quantitativa' => $sugestao !== null,
                'tem_exame' => $this->padrao->temExameProprio((string) $nivel->codigo),
                // Creche e Pré-Escolar são qualitativos; as classes secundárias seguintes têm notas, mas ficam fora desta fase.
                'ambito' => $sugestao !== null ? 'quantitativa' : (in_array($nivel->etapa_ensino, [EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRE_ESCOLAR], true) ? 'qualitativa' : 'fora_de_ambito'),
                'regra' => $regra === null ? null : [
                    'id' => $regra->id,
                    'escala_maxima' => $regra->escala_maxima,
                    'nota_minima' => (string) $regra->nota_minima,
                    'peso_mac' => (string) $regra->peso_mac,
                    'peso_pt' => (string) $regra->peso_pt,
                    'peso_mt1' => $regra->peso_mt1 === null ? null : (string) $regra->peso_mt1,
                    'peso_mt2' => $regra->peso_mt2 === null ? null : (string) $regra->peso_mt2,
                    'peso_mt3' => $regra->peso_mt3 === null ? null : (string) $regra->peso_mt3,
                    'peso_mfd_exame' => (string) $regra->peso_mfd_exame,
                    'peso_exame' => (string) $regra->peso_exame,
                    'casas_decimais' => $regra->casas_decimais,
                    'modo_arredondamento' => $regra->modo_arredondamento->value,
                    'reprova_por_aproveitamento' => $regra->reprova_por_aproveitamento,
                ],
                'regra_qualitativa' => isset($regrasQ[$nivel->id]) ? ['escala_id' => $regrasQ[$nivel->id]->escala_id] : null,
                'sugestao' => $sugestao,
                'prazos' => $regra === null ? [] : $prazos->where('regra_id', $regra->id)->map(fn (AvaliacaoPrazo $p) => [
                    'id' => $p->id,
                    'periodo_id' => $p->periodo_id,
                    'tipo' => $p->tipo->value,
                    'data_limite' => $p->data_limite->toDateString(),
                    'excepcoes' => $p->excepcoes->map(fn ($e) => [
                        'id' => $e->id, 'turma_id' => $e->turma_id, 'disciplina_id' => $e->disciplina_id, 'docente_id' => $e->docente_id,
                        'data_limite' => $e->data_limite->toDateString(), 'motivo' => $e->motivo,
                    ])->values()->all(),
                ])->values()->all(),
                'turmas' => ($turmas[$nivel->id] ?? collect())->map(fn (Turma $t) => ['id' => $t->id, 'rotulo' => $t->rotulo(completo: true)])->values()->all(),
            ];
        })->all();

        return [
            'anos' => $anos->map(fn (AnoLectivo $a) => ['id' => $a->id, 'nome' => $a->nome])->all(),
            'ano_id' => $ano->id,
            'classes' => $classes,
            'periodos' => Periodo::query()->where('ano_lectivo_id', $ano->id)->where('tipo', TipoPeriodo::TRIMESTRE)->orderBy('numero')->get()
                ->map(fn (Periodo $p) => ['id' => $p->id, 'nome' => $p->nome, 'numero' => $p->numero, 'data_fim' => $p->data_fim->toDateString()])->all(),
            'modos' => $this->modos(),
            'tipos' => $this->tipos(),
            'escalas' => $this->escalas(),
            ...$this->documentos(),
        ];
    }

    /** @return array{documentos: array<string, ?string>, cabecalho_linhas: list<string>} */
    private function documentos(): array
    {
        $c = AvaliacaoDocumentoConfig::query()->first();

        return [
            'documentos' => ['linhas_extra' => $c?->linhas_extra, 'lema' => $c?->lema, 'visto_cargo' => $c?->visto_cargo, 'visto_nome' => $c?->visto_nome],
            'cabecalho_linhas' => $this->cabecalho->dados()['linhas'],
        ];
    }

    private function escalas(): array
    {
        return AvaliacaoEscala::query()->with('niveis')->orderBy('nome')->get()->map(fn (AvaliacaoEscala $e) => [
            'id' => $e->id, 'nome' => $e->nome,
            'niveis' => $e->niveis->map(fn ($n) => ['id' => $n->id, 'codigo' => $n->codigo, 'designacao' => $n->designacao])->all(),
        ])->all();
    }

    private function modos(): array
    {
        return array_map(fn (ModoArredondamento $m) => ['valor' => $m->value, 'nome' => $m->label()], ModoArredondamento::cases());
    }

    private function tipos(): array
    {
        return array_map(fn (TipoInstrumento $t) => ['valor' => $t->value, 'nome' => $t->label()], TipoInstrumento::cases());
    }
}
