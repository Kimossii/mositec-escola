<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Actions\CopiarRegrasDoAnoAnteriorAction;
use Modules\Avaliacao\Actions\DefinirPrazoAction;
use Modules\Avaliacao\Actions\GuardarCompetenciasAction;
use Modules\Avaliacao\Actions\GuardarConfigDocumentosAction;
use Modules\Avaliacao\Actions\GuardarEscalaQualitativaAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\GuardarRegraQualitativaAction;
use Modules\Avaliacao\Actions\ProlongarPrazoAction;
use Modules\Avaliacao\DTO\CompetenciaDTO;
use Modules\Avaliacao\DTO\CompetenciasDaAreaDTO;
use Modules\Avaliacao\DTO\ConfigDocumentosDTO;
use Modules\Avaliacao\DTO\DefinirPrazoDTO;
use Modules\Avaliacao\DTO\EscalaQualitativaDTO;
use Modules\Avaliacao\DTO\NivelDaEscalaDTO;
use Modules\Avaliacao\DTO\ProlongarPrazoDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\DTO\RegraQualitativaDTO;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Enums\Progressao;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Http\Requests\CopiarRegrasRequest;
use Modules\Avaliacao\Http\Requests\DefinirPrazoRequest;
use Modules\Avaliacao\Http\Requests\GuardarCompetenciasRequest;
use Modules\Avaliacao\Http\Requests\GuardarConfigDocumentosRequest;
use Modules\Avaliacao\Http\Requests\GuardarEscalaRequest;
use Modules\Avaliacao\Http\Requests\GuardarRegraQualitativaRequest;
use Modules\Avaliacao\Http\Requests\GuardarRegraRequest;
use Modules\Avaliacao\Http\Requests\ProlongarPrazoRequest;

/** Os pedidos de escrita da configuração da avaliação: monta o DTO a partir do pedido e entrega à Action. */
class GestaoConfiguracaoAvaliacaoService
{
    public function __construct(
        private GuardarRegraAvaliacaoAction $guardarRegra,
        private CopiarRegrasDoAnoAnteriorAction $copiarRegras,
        private DefinirPrazoAction $definirPrazo,
        private ProlongarPrazoAction $prolongarPrazo,
        private GuardarEscalaQualitativaAction $guardarEscala,
        private GuardarRegraQualitativaAction $guardarRegraQualitativa,
        private GuardarCompetenciasAction $guardarCompetencias,
        private GuardarConfigDocumentosAction $guardarDocumentos,
    ) {
    }

    public function guardarRegra(GuardarRegraRequest $request): void
    {
        $d = $request->validated();
        $texto = fn (string $campo) => isset($d[$campo]) ? (string) $d[$campo] : null;

        $this->guardarRegra->executar(new RegraAvaliacaoDTO(
            ano_lectivo_id: (int) $d['ano_lectivo_id'],
            nivel_academico_id: (int) $d['nivel_academico_id'],
            escala_maxima: (int) $d['escala_maxima'],
            nota_minima: (string) $d['nota_minima'],
            peso_mac: (string) $d['peso_mac'],
            peso_pt: (string) $d['peso_pt'],
            peso_mt1: $texto('peso_mt1'),
            peso_mt2: $texto('peso_mt2'),
            peso_mt3: $texto('peso_mt3'),
            casas_decimais: (int) $d['casas_decimais'],
            modo_arredondamento: ModoArredondamento::from((int) $d['modo_arredondamento']),
            reprova_por_aproveitamento: (bool) $d['reprova_por_aproveitamento'],
            peso_mfd_exame: $texto('peso_mfd_exame') ?? '40',
            peso_exame: $texto('peso_exame') ?? '60',
            recurso_max_disciplinas: isset($d['recurso_max_disciplinas']) ? (int) $d['recurso_max_disciplinas'] : null,
            disciplinas_chave: array_map('intval', $d['disciplinas_chave'] ?? []),
        ));
    }

    /** @return int quantas regras se copiaram */
    public function copiarRegras(CopiarRegrasRequest $request): int
    {
        return $this->copiarRegras->executar((int) $request->validated()['ano_lectivo_id']);
    }

    public function definirPrazo(DefinirPrazoRequest $request): void
    {
        $d = $request->validated();

        $this->definirPrazo->executar(new DefinirPrazoDTO((int) $d['regra_id'], (int) $d['periodo_id'], TipoInstrumento::from((int) $d['tipo']), $d['data_limite']));
    }

    public function prolongarPrazo(int $prazoId, ProlongarPrazoRequest $request): void
    {
        $d = $request->validated();
        $inteiro = fn (string $campo) => isset($d[$campo]) ? (int) $d[$campo] : null;

        $this->prolongarPrazo->executar(new ProlongarPrazoDTO(
            $prazoId, $d['data_limite'], $d['motivo'], $inteiro('turma_id'), $inteiro('disciplina_id'), $inteiro('docente_id'),
        ));
    }

    public function guardarEscala(GuardarEscalaRequest $request): void
    {
        $d = $request->validated();

        $this->guardarEscala->executar(new EscalaQualitativaDTO(
            $d['nome'],
            array_map(fn (array $n) => new NivelDaEscalaDTO($n['codigo'], $n['designacao'], isset($n['id']) ? (int) $n['id'] : null), array_values($d['niveis'])),
            isset($d['id']) ? (int) $d['id'] : null,
        ));
    }

    public function guardarRegraQualitativa(GuardarRegraQualitativaRequest $request): void
    {
        $d = $request->validated();

        $this->guardarRegraQualitativa->executar(new RegraQualitativaDTO(
            (int) $d['ano_lectivo_id'], (int) $d['nivel_academico_id'], (int) $d['escala_id'], Progressao::from((int) ($d['progressao'] ?? Progressao::AUTOMATICA->value)),
        ));
    }

    public function guardarCompetencias(GuardarCompetenciasRequest $request): void
    {
        $d = $request->validated();

        $this->guardarCompetencias->executar(new CompetenciasDaAreaDTO(
            (int) $d['disciplina_id'],
            isset($d['nivel_academico_id']) ? (int) $d['nivel_academico_id'] : null,
            array_map(fn (array $c) => new CompetenciaDTO($c['descricao'], (bool) ($c['ativa'] ?? true), isset($c['id']) ? (int) $c['id'] : null), array_values($d['competencias'])),
        ));
    }

    public function guardarDocumentos(GuardarConfigDocumentosRequest $request): void
    {
        $d = $request->validated();

        $this->guardarDocumentos->executar(new ConfigDocumentosDTO($d['linhas_extra'] ?? null, $d['lema'] ?? null, $d['visto_cargo'] ?? null, $d['visto_nome'] ?? null));
    }
}
