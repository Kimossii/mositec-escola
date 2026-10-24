<?php

namespace App\Services;

use Modules\Aluno\Services\AlunoConsultaService;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Services\AcordoConsultaService;
use Modules\Financeiro\Services\DividasConsultaService;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PropinaConsultaService;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\MatriculaConsultaService;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;

/**
 * Dados do Dashboard, sempre do ano lectivo activo e só dos módulos que o utilizador pode ver: um bloco sem
 * permissão nem sequer é calculado. Reaproveita os serviços de consulta (e a sua cache) das listas.
 */
class DashboardService
{
    public function __construct(
        private AlunoConsultaService $alunos,
        private MatriculaConsultaService $matriculas,
        private PropinaConsultaService $propinas,
        private DividasConsultaService $dividas,
        private PagamentoConsultaService $pagamentos,
        private AcordoConsultaService $acordos,
        private RelogioDoTenant $relogio,
        private MoedaDoTenant $moeda,
    ) {
    }

    /**
     * Cartões do topo. `tipo` diz ao frontend como formatar o valor: `numero` ou `dinheiro` (unidades menores).
     *
     * @return list<array{rotulo: string, valor: int, tipo: string, nota: string, cor: string}>
     */
    public function cartoes(User $user): array
    {
        $filtros = ['ano_lectivo_id' => $this->anoActivoId()];
        $cartoes = [];

        if ($user->can('aluno.ver')) {
            $alunos = $this->alunos->indicadores($filtros);
            $cartoes[] = ['rotulo' => 'Alunos activos', 'valor' => $alunos['com_matricula_activa'], 'tipo' => 'numero', 'nota' => "{$alunos['total']} alunos registados", 'cor' => 'primary'];
        }

        if ($user->can('matricula.ver')) {
            $matriculas = $this->matriculas->indicadores($filtros);
            $cartoes[] = ['rotulo' => 'Matrículas pendentes', 'valor' => $matriculas['pendentes'], 'tipo' => 'numero', 'nota' => 'por activar', 'cor' => 'warning'];
        }

        if ($user->can('pagamento.ver')) {
            $hoje = $this->relogio->hoje();
            $mes = $this->pagamentos->indicadores(['data_de' => $hoje->startOfMonth()->toDateString(), 'data_ate' => $hoje->toDateString()]);
            $cartoes[] = ['rotulo' => 'Cobrado este mês', 'valor' => $mes['recebido'], 'tipo' => 'dinheiro', 'nota' => "{$mes['pagamentos']} pagamento(s)", 'cor' => 'success'];
        }

        if ($user->can('propina.ver')) {
            $dividas = $this->dividas->totais($filtros);
            $cartoes[] = ['rotulo' => 'Em dívida', 'valor' => $dividas['total'], 'tipo' => 'dinheiro', 'nota' => "{$dividas['matriculas']} matrícula(s) com dívida", 'cor' => 'danger'];
        }

        return $cartoes;
    }

    /**
     * Itens que pedem atenção hoje, só os que têm ocorrências. `valor` (opcional) está em unidades menores.
     *
     * @return list<array{rotulo: string, quantidade: int, valor: int|null, cor: string, link: string}>
     */
    public function atencao(User $user): array
    {
        $ano = $this->anoActivoId();
        $itens = [];

        if ($user->can('propina.ver')) {
            $propinas = $this->propinas->indicadores(['ano_lectivo_id' => $ano]);
            $itens[] = ['rotulo' => 'Propinas em atraso', 'quantidade' => $propinas['em_atraso_n'], 'valor' => $propinas['em_atraso_valor'], 'cor' => 'danger', 'link' => '/financeiro/propinas?estado=7'];
        }

        if ($user->can('acordo.ver')) {
            $itens[] = ['rotulo' => 'Acordos de dívida em atraso', 'quantidade' => $this->acordos->indicadores()['em_atraso'], 'valor' => null, 'cor' => 'danger', 'link' => '/financeiro/acordos'];
        }

        if ($user->can('matricula.ver')) {
            $pendentes = $this->matriculas->indicadores(['ano_lectivo_id' => $ano])['pendentes'];
            $itens[] = ['rotulo' => 'Matrículas por activar', 'quantidade' => $pendentes, 'valor' => null, 'cor' => 'warning', 'link' => '/matriculas?estado='.EstadoMatriculaEnum::PENDENTE->value];
        }

        if ($user->can('turmas.ver')) {
            $itens[] = ['rotulo' => 'Turmas activas sem alunos', 'quantidade' => $this->turmasSemAlunos($ano), 'valor' => null, 'cor' => 'warning', 'link' => '/turmas'];
        }

        return array_values(array_filter($itens, fn (array $item) => $item['quantidade'] > 0));
    }

    /**
     * Dados dos gráficos, só dos módulos que o utilizador pode ver.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function graficos(User $user): array
    {
        $filtros = ['ano_lectivo_id' => $this->anoActivoId()];
        $graficos = [];

        if ($user->can('propina.ver')) {
            $graficos['propinas_mes'] = $this->propinas->porMes($filtros);
        }

        if ($user->can('matricula.ver')) {
            $graficos['alunos_curso'] = $this->matriculas->activasPorCurso($filtros);
        }

        return $graficos;
    }

    /** @return list<array{numero: string, data: string|null, valor: int, aluno: string|null}> */
    public function ultimosPagamentos(User $user): array
    {
        return $user->can('pagamento.ver') ? $this->pagamentos->ultimos() : [];
    }

    /**
     * Frases-resumo do dia, escritas por regras a partir dos números reais. `**texto**` marca o que o frontend
     * destaca. Só entram frases dos módulos a que o utilizador tem acesso.
     *
     * @return list<string>
     */
    public function resumo(User $user): array
    {
        $ano = ['ano_lectivo_id' => $this->anoActivoId()];
        $hoje = $this->relogio->hoje();
        $frases = [];

        if ($user->can('pagamento.ver')) {
            $semana = $this->pagamentos->indicadores(['data_de' => $hoje->startOfWeek()->toDateString(), 'data_ate' => $hoje->toDateString()]);
            $anterior = $this->pagamentos->indicadores(['data_de' => $hoje->startOfWeek()->subWeek()->toDateString(), 'data_ate' => $hoje->subWeek()->toDateString()]);
            $variacao = $anterior['recebido'] > 0 ? (int) round(($semana['recebido'] - $anterior['recebido']) / $anterior['recebido'] * 100) : null;
            $comparacao = $variacao === null ? '' : ' (**'.($variacao >= 0 ? '+' : '').$variacao.'%** face à semana passada)';
            $frases[] = $semana['recebido'] > 0
                ? 'Esta semana entraram **'.$this->dinheiro($semana['recebido']).'**'.$comparacao.'.'
                : 'Ainda não entrou nenhum pagamento esta semana.';
        }

        if ($user->can('propina.ver')) {
            $porTurma = $this->dividas->linhas($ano)->groupBy('turma_nome')->map(fn ($l) => (int) $l->sum('total'))->sortDesc();
            $total = (int) $porTurma->sum();

            if ($total > 0) {
                $acumulado = 0;
                $turmas = 0;
                foreach ($porTurma as $valor) {
                    $acumulado += $valor;
                    $turmas++;
                    if ($acumulado * 100 >= $total * 50) {
                        break;
                    }
                }
                $frases[] = '**'.$turmas.($turmas === 1 ? ' turma concentra' : ' turmas concentram').'** '.(int) round($acumulado / $total * 100).'% da dívida de **'.$this->dinheiro($total).'**.';
            } else {
                $frases[] = 'Não há dívida em aberto neste ano lectivo.';
            }

            $previsao = $this->propinas->previsaoDeCaixa();
            if ($previsao['previsto'] !== null && $previsao['a_vencer'] > 0) {
                $frases[] = 'Nos próximos 30 dias prevê-se receber **'.$this->dinheiro($previsao['previsto']).'** de '.$this->dinheiro($previsao['a_vencer']).' a vencer.';
            }
        }

        if ($user->can('acordo.ver')) {
            $aVencer = $this->acordos->aVencer($hoje->toDateString());
            if ($aVencer['quantidade'] > 0) {
                $frases[] = '**'.$aVencer['quantidade'].($aVencer['quantidade'] === 1 ? ' prestação' : ' prestações').'** de acordos vence'.($aVencer['quantidade'] === 1 ? '' : 'm').' nos próximos 7 dias ('.$this->dinheiro($aVencer['valor']).').';
            }
        }

        return $frases;
    }

    /**
     * Alunos em risco de incumprimento: 2 ou mais meses em atraso e sem acordo válido (sem acordo ou com acordo em atraso),
     * os mais graves primeiro. Cruza a matrícula com a dívida.
     *
     * @return array{total: int, valor: int, alunos: list<array<string, mixed>>}
     */
    public function risco(User $user, int $limite = 6): array
    {
        if (! $user->can('propina.ver')) {
            return ['total' => 0, 'valor' => 0, 'alunos' => []];
        }

        $emRisco = $this->dividas->linhas(['ano_lectivo_id' => $this->anoActivoId()])
            ->filter(fn (array $l) => $l['meses'] >= 2 && ($l['acordo'] === null || $l['acordo']['em_atraso']))
            ->sortByDesc(fn (array $l) => [$l['acordo'] !== null, $l['meses'], $l['total']])
            ->values();

        return [
            'total' => $emRisco->count(),
            'valor' => (int) $emRisco->sum('total'),
            'alunos' => $emRisco->take($limite)->map(fn (array $l) => [
                'matricula_id' => $l['matricula_id'],
                'aluno_id' => $l['aluno_id'],
                'aluno' => $l['aluno_nome'],
                'turma' => $l['turma_nome'],
                'meses' => $l['meses'],
                'total' => $l['total'],
                'motivo' => $l['acordo'] === null ? 'Sem acordo' : 'Acordo em atraso',
                'nivel' => $l['acordo'] !== null || $l['meses'] >= 4 ? 'alto' : 'medio',
            ])->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function previsao(User $user): ?array
    {
        return $user->can('propina.ver') ? $this->propinas->previsaoDeCaixa() : null;
    }

    /** @return array<string, mixed>|null */
    public function calendario(User $user): ?array
    {
        return $user->can('propina.ver') ? $this->propinas->calendarioDoMes() : null;
    }

    private function dinheiro(int $unidadesMenores): string
    {
        return Dinheiro::deUnidadesMenores($unidadesMenores)->formatar($this->moeda->atual());
    }

    private function anoActivoId(): ?int
    {
        return AnoLectivo::current(Estabelecimento::current()?->id)?->id;
    }

    private function turmasSemAlunos(?int $ano): int
    {
        $comAlunos = Matricula::query()
            ->whereIn('estado', [EstadoMatriculaEnum::PENDENTE->value, EstadoMatriculaEnum::ACTIVA->value])
            ->select('turma_id');

        return Turma::query()
            ->where('estado', 1)
            ->when($ano !== null, fn ($q) => $q->where('ano_lectivo_id', $ano))
            ->whereNotIn('id', $comAlunos)
            ->count();
    }
}
