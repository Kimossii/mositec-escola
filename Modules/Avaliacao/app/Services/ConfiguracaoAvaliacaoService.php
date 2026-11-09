<?php

namespace Modules\Avaliacao\Services;

use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Avaliacao\Enums\Progressao;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoCompetencia;
use Modules\Avaliacao\Models\AvaliacaoDocumentoConfig;
use Modules\Disciplina\Models\Disciplina;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;

/** Leitura da página de configuração: as regras do ano e as classes ligadas a cada uma, prazos por trimestre e tipo, e excepções. Só consulta. */
class ConfiguracaoAvaliacaoService
{
    public function __construct(private CabecalhoDocumentoService $cabecalho, private DefinicoesDeAvaliacaoService $definicoes, private RegraPadraoService $padrao)
    {
    }

    /** O ano lectivo que a página mostra: o pedido, senão o activo, senão o mais recente. */
    public function anoSelecionado(?int $anoLectivoId = null): ?AnoLectivo
    {
        $anos = AnoLectivo::query()->orderByDesc('data_inicio')->get();

        return $anos->firstWhere('id', $anoLectivoId) ?? $anos->firstWhere('estado', EstadoAnoLectivo::ATIVO) ?? $anos->first();
    }

    /** @return array<string, mixed> */
    public function pagina(?int $anoLectivoId = null): array
    {
        $anos = AnoLectivo::query()->orderByDesc('data_inicio')->get();
        $ano = $this->anoSelecionado($anoLectivoId);

        $comum = ['modos' => $this->modos(), 'tipos' => $this->tipos(), 'escalas' => $this->escalas(), 'progressoes' => array_map(fn (Progressao $p) => ['valor' => $p->value, 'nome' => $p->label()], Progressao::cases()),
            ...$this->competencias(), ...$this->documentos(), 'definicoes' => $this->definicoes->paraTela()];

        if ($ano === null) {
            return ['anos' => [], 'ano_id' => null, 'regras' => [], 'niveis' => [], 'periodos' => [], 'prazos' => [], 'tipos_de_prazo' => [], 'turmas' => [], ...$comum];
        }

        $regras = AvaliacaoRegra::query()->where('ano_lectivo_id', $ano->id)->with(['disciplinasChave:disciplinas.id', 'niveis'])->orderBy('id')->get();
        $regrasQ = AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $ano->id)->with('niveis')->orderBy('id')->get();
        $periodos = Periodo::query()->where('ano_lectivo_id', $ano->id)->where('tipo', TipoPeriodo::TRIMESTRE)->orderBy('numero')->get();
        $prazos = AvaliacaoPrazo::query()->whereIn('periodo_id', $periodos->pluck('id'))->with('excepcoes')->get();
        $turmas = Turma::query()->where('ano_lectivo_id', $ano->id)->with(['turno:id,nome', 'anoLectivo:id,nome'])->get();

        $prazosDe = fn ($lista) => $lista->map(fn (AvaliacaoPrazo $p) => [
            'id' => $p->id,
            'periodo_id' => $p->periodo_id,
            'tipo' => $p->tipo->value,
            'data_limite' => $p->data_limite->toDateString(),
            'excepcoes' => $p->excepcoes->map(fn ($e) => [
                'id' => $e->id, 'turma_id' => $e->turma_id, 'disciplina_id' => $e->disciplina_id, 'docente_id' => $e->docente_id,
                'data_limite' => $e->data_limite->toDateString(), 'motivo' => $e->motivo,
            ])->values()->all(),
        ])->values()->all();

        $numericas = $regras->map(fn (AvaliacaoRegra $r) => [
            'id' => $r->id,
            'qualitativa' => false,
            'nome' => $r->nome,
            'nivel_ids' => $r->niveis->pluck('nivel_academico_id')->all(),
            'escala_maxima' => $r->escala_maxima,
            'nota_minima' => (string) $r->nota_minima,
            'peso_mac' => (string) $r->peso_mac,
            'peso_pt' => (string) $r->peso_pt,
            'sigla_prova' => $r->sigla_prova,
            'peso_mt1' => $r->peso_mt1 === null ? null : (string) $r->peso_mt1,
            'peso_mt2' => $r->peso_mt2 === null ? null : (string) $r->peso_mt2,
            'peso_mt3' => $r->peso_mt3 === null ? null : (string) $r->peso_mt3,
            'peso_mfd_exame' => (string) $r->peso_mfd_exame,
            'peso_exame' => (string) $r->peso_exame,
            'com_exame' => $r->com_exame,
            'com_recurso' => $r->com_recurso,
            'recurso_max_disciplinas' => $r->recurso_max_disciplinas,
            'disciplinas_chave' => $r->disciplinasChave->pluck('id')->all(),
            'casas_decimais' => $r->casas_decimais,
            'modo_arredondamento' => $r->modo_arredondamento->value,
            'reprova_por_aproveitamento' => $r->reprova_por_aproveitamento,
        ]);

        $qualitativas = $regrasQ->map(fn (AvaliacaoRegraQualitativa $r) => [
            'id' => $r->id,
            'qualitativa' => true,
            'nome' => $r->nome,
            'nivel_ids' => $r->niveis->pluck('nivel_academico_id')->all(),
            'escala_id' => $r->escala_id,
            'progressao' => $r->progressao->value,
        ]);

        $ligadas = $regras->flatMap->niveis->merge($regrasQ->flatMap->niveis)->pluck('nivel_academico_id')->all();

        return [
            'anos' => $anos->map(fn (AnoLectivo $a) => ['id' => $a->id, 'nome' => $a->nome])->all(),
            'ano_id' => $ano->id,
            'regras' => $qualitativas->concat($numericas)->values()->all(),
            // Todas as classes, para as ligar a uma regra; as que não seguem nenhuma aparecem à parte.
            'niveis' => NivelAcademico::query()->orderBy('ordem')->get()->map(fn (NivelAcademico $n) => [
                'id' => $n->id, 'codigo' => $n->codigo, 'nome' => $n->nome,
                'qualitativo' => in_array($n->etapa_ensino, [EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRE_ESCOLAR], true),
                'tem_regra_padrao' => $this->padrao->regraDe((string) $n->codigo) !== null,
                'sem_regra' => ! in_array($n->id, $ligadas, true),
            ])->all(),
            'periodos' => $periodos->map(fn (Periodo $p) => ['id' => $p->id, 'nome' => $p->nome, 'numero' => $p->numero, 'data_fim' => $p->data_fim->toDateString()])->all(),
            // Os prazos são da escola, por trimestre e tipo, e valem para todas as regras.
            'prazos' => $prazosDe($prazos),
            'tipos_de_prazo' => $this->tiposDePrazo($regras, $regrasQ),
            'turmas' => $turmas->map(fn (Turma $t) => ['id' => $t->id, 'rotulo' => $t->rotulo(completo: true)])->values()->all(),
            ...$comum,
        ];
    }

    /**
     * Os tipos de prazo que a escola usa: contínuas e prova sempre; exame e recurso se alguma regra os tem; a avaliação
     * qualitativa se há regras qualitativas.
     *
     * @return list<int>
     */
    private function tiposDePrazo($regras, $regrasQ): array
    {
        return array_values(array_filter([
            TipoInstrumento::MAC->value,
            TipoInstrumento::PT->value,
            $regras->contains('com_exame', true) ? TipoInstrumento::EXAME->value : null,
            $regras->contains('com_recurso', true) ? TipoInstrumento::RECURSO->value : null,
            $regrasQ->isNotEmpty() ? TipoInstrumento::QUALITATIVA->value : null,
        ], fn ($t) => $t !== null));
    }

    /** @return array{competencias: list<array<string, mixed>>, disciplinas: list<array<string, mixed>>} */
    private function competencias(): array
    {
        return [
            'competencias' => AvaliacaoCompetencia::query()->orderBy('ordem')->orderBy('id')->get()->map(fn (AvaliacaoCompetencia $c) => [
                'id' => $c->id, 'disciplina_id' => $c->disciplina_id, 'nivel_academico_id' => $c->nivel_academico_id, 'descricao' => $c->descricao, 'ativa' => $c->ativa,
            ])->all(),
            'disciplinas' => Disciplina::query()->orderBy('nome')->get(['id', 'nome'])->map(fn (Disciplina $d) => ['id' => $d->id, 'nome' => (string) $d->nome])->all(),
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
