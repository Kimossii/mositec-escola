<?php

namespace Modules\Avaliacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\Avaliacao\Actions\CopiarRegrasDoAnoAnteriorAction;
use Modules\Avaliacao\Actions\DefinirPrazoAction;
use Modules\Avaliacao\Actions\GuardarEscalaQualitativaAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\GuardarRegraQualitativaAction;
use Modules\Avaliacao\Actions\ProlongarPrazoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Services\ConfiguracaoAvaliacaoService;

class ConfiguracaoAvaliacaoController extends Controller
{
    public function index(Request $request, ConfiguracaoAvaliacaoService $servico)
    {
        $this->authorize('nota.configurar');
        $f = $request->validate(['ano' => ['nullable', 'integer']]);

        return Inertia::render('Avaliacao/Configuracao/Index', $servico->pagina($f['ano'] ?? null));
    }

    public function guardarRegra(Request $request, GuardarRegraAvaliacaoAction $acao): RedirectResponse
    {
        $d = $request->validate([
            'ano_lectivo_id' => ['required', 'integer'], 'nivel_academico_id' => ['required', 'integer'],
            'escala_maxima' => ['required', 'integer', 'between:1,100'], 'nota_minima' => ['required', 'numeric', 'min:0'],
            'peso_mac' => ['required', 'numeric', 'between:0,100'], 'peso_pt' => ['required', 'numeric', 'between:0,100'],
            'peso_mt1' => ['nullable', 'numeric', 'between:0,100'], 'peso_mt2' => ['nullable', 'numeric', 'between:0,100'], 'peso_mt3' => ['nullable', 'numeric', 'between:0,100'],
            'peso_mfd_exame' => ['nullable', 'numeric', 'between:0,100'], 'peso_exame' => ['nullable', 'numeric', 'between:0,100'],
            'casas_decimais' => ['required', 'integer', 'between:0,4'],
            'modo_arredondamento' => ['required', Rule::in(array_column(ModoArredondamento::cases(), 'value'))],
            'reprova_por_aproveitamento' => ['required', 'boolean'],
        ]);

        $acao->executar(new RegraAvaliacaoDTO(
            ano_lectivo_id: $d['ano_lectivo_id'], nivel_academico_id: $d['nivel_academico_id'], escala_maxima: $d['escala_maxima'],
            nota_minima: (string) $d['nota_minima'], peso_mac: (string) $d['peso_mac'], peso_pt: (string) $d['peso_pt'],
            peso_mt1: isset($d['peso_mt1']) ? (string) $d['peso_mt1'] : null, peso_mt2: isset($d['peso_mt2']) ? (string) $d['peso_mt2'] : null,
            peso_mt3: isset($d['peso_mt3']) ? (string) $d['peso_mt3'] : null, casas_decimais: $d['casas_decimais'],
            modo_arredondamento: ModoArredondamento::from((int) $d['modo_arredondamento']), reprova_por_aproveitamento: (bool) $d['reprova_por_aproveitamento'],
            peso_mfd_exame: (string) ($d['peso_mfd_exame'] ?? '40'), peso_exame: (string) ($d['peso_exame'] ?? '60'),
        ));

        return back();
    }

    public function guardarEscala(Request $request, GuardarEscalaQualitativaAction $acao): RedirectResponse
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer'], 'nome' => ['required', 'string', 'max:120'],
            'niveis' => ['required', 'array', 'min:2', 'max:12'], 'niveis.*.id' => ['nullable', 'integer'],
            'niveis.*.codigo' => ['required', 'string', 'max:20'], 'niveis.*.designacao' => ['required', 'string', 'max:120'],
        ]);

        $acao->executar($d['id'] ?? null, $d['nome'], array_values($d['niveis']));

        return back();
    }

    public function guardarRegraQualitativa(Request $request, GuardarRegraQualitativaAction $acao): RedirectResponse
    {
        $d = $request->validate(['ano_lectivo_id' => ['required', 'integer'], 'nivel_academico_id' => ['required', 'integer'], 'escala_id' => ['required', 'integer']]);

        $acao->executar($d['ano_lectivo_id'], $d['nivel_academico_id'], $d['escala_id']);

        return back();
    }

    public function definirPrazo(Request $request, DefinirPrazoAction $acao): RedirectResponse
    {
        $d = $request->validate([
            'regra_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(array_column(TipoInstrumento::cases(), 'value'))], 'data_limite' => ['required', 'date_format:Y-m-d'],
        ]);

        $acao->executar($d['regra_id'], $d['periodo_id'], TipoInstrumento::from((int) $d['tipo']), $d['data_limite']);

        return back();
    }

    public function prolongarPrazo(Request $request, int $prazo, ProlongarPrazoAction $acao): RedirectResponse
    {
        $d = $request->validate([
            'data_limite' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'max:500'],
            'turma_id' => ['nullable', 'integer'], 'disciplina_id' => ['nullable', 'integer'], 'docente_id' => ['nullable', 'integer'],
        ]);

        $acao->executar($prazo, $d['data_limite'], $d['motivo'], $d['turma_id'] ?? null, $d['disciplina_id'] ?? null, $d['docente_id'] ?? null);

        return back();
    }

    public function copiar(Request $request, CopiarRegrasDoAnoAnteriorAction $acao): RedirectResponse
    {
        $d = $request->validate(['ano_lectivo_id' => ['required', 'integer']]);

        $copiadas = $acao->executar($d['ano_lectivo_id']);

        return back()->with('success', $copiadas === 0 ? 'Não havia regras para copiar.' : "{$copiadas} regra(s) copiada(s) do ano anterior.");
    }
}
