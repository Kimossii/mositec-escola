<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\RotuloDePeriodo;

/**
 * Leitura das propinas para a lista (spec §10). O estado mostrado e filtrado é o resolvido para o "hoje"
 * da escola (§4, §8.2b): o filtro usa o scope SQL de F1, a coluna usa EstadoCobranca::resolver.
 */
class PropinaConsultaService
{
    public const POR_PAGINA = 15;

    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    public function listar(array $filtros = []): LengthAwarePaginator
    {
        $hoje = $this->relogio->hoje();
        $fuso = $this->relogio->fuso();
        $estado = is_numeric($filtros['estado'] ?? null) ? EstadoCobranca::tryFrom((int) $filtros['estado']) : null;
        $origem = is_numeric($filtros['origem_geracao'] ?? null) ? OrigemGeracaoPropina::tryFrom((int) $filtros['origem_geracao']) : null;
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';

        return Propina::query()
            ->with(['matricula.aluno.dadosPessoa', 'matricula.turma', 'plano'])
            ->when(is_numeric($filtros['ano_lectivo_id'] ?? null), fn ($query) => $query->where('ano_lectivo_id', (int) $filtros['ano_lectivo_id']))
            ->when($estado !== null, fn ($query) => $query->comEstadoResolvido($estado, $hoje))
            ->when(is_numeric($filtros['plano_propina_id'] ?? null), fn ($query) => $query->where('plano_propina_id', (int) $filtros['plano_propina_id']))
            ->when(is_numeric($filtros['matricula_id'] ?? null), fn ($query) => $query->where('matricula_id', (int) $filtros['matricula_id']))
            ->when($origem !== null, fn ($query) => $query->where('origem_geracao', $origem->value))
            ->when($this->data($filtros['vencimento_de'] ?? null), fn ($query, string $dia) => $query->where('data_vencimento', '>=', $dia))
            ->when($this->data($filtros['vencimento_ate'] ?? null), fn ($query, string $dia) => $query->where('data_vencimento', '<=', $dia))
            ->when($pesquisa !== '', fn ($query) => $query->whereHas('matricula', fn ($matricula) => $matricula->where(fn ($grupo) => $grupo
                ->whereContem('numero_registo_matricula', $pesquisa)
                ->orWhereHas('aluno', fn ($aluno) => $aluno->where(fn ($subgrupo) => $subgrupo
                    ->whereContem('numero_matricula', $pesquisa)
                    ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $pesquisa)))))))
            ->orderBy('data_vencimento')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (Propina $propina) => $this->linha($propina, $hoje, $fuso));
    }

    /**
     * @return Collection<int, PlanoPropina>
     */
    public function planos(): Collection
    {
        return PlanoPropina::query()->orderBy('nome')->get(['id', 'nome', 'ano_lectivo_id', 'estado']);
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function estados(): array
    {
        return array_map(fn (EstadoCobranca $e) => ['value' => $e->value, 'label' => $e->label()], EstadoCobranca::cases());
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function origens(): array
    {
        return array_map(fn (OrigemGeracaoPropina $o) => ['value' => $o->value, 'label' => $o->label()], OrigemGeracaoPropina::cases());
    }

    /**
     * @return array<string, mixed>
     */
    private function linha(Propina $propina, CarbonImmutable $hoje, string $fuso): array
    {
        $estado = $propina->estadoResolvido($hoje);
        $inicio = $propina->periodo_inicio->toDateString();
        $fim = $propina->periodo_fim->toDateString();
        $vencimento = $propina->data_vencimento->toDateString();
        $criadaNaEscola = $propina->created_at?->copy()->setTimezone($fuso)->toDateString();

        return [
            'id' => $propina->id,
            'matricula_id' => $propina->matricula_id,
            'matricula_numero' => $propina->matricula?->numero_registo_matricula,
            'aluno_nome' => $propina->matricula?->aluno?->dadosPessoa?->nome_completo,
            'aluno_numero' => $propina->matricula?->aluno?->numero_matricula,
            'turma_nome' => $propina->matricula?->turma?->nome,
            'plano_propina_id' => $propina->plano_propina_id,
            'plano_nome' => $propina->plano?->nome,
            'ordem' => $propina->ordem,
            'periodo_inicio' => $inicio,
            'periodo_fim' => $fim,
            'periodo' => RotuloDePeriodo::periodo($inicio, $fim),
            'data_vencimento' => $vencimento,
            'data_limite' => $propina->data_limite->toDateString(),
            'valor' => $propina->valor->unidadesMenores(),
            'valor_pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
            'estado' => $estado->value,
            'estado_descricao' => $estado->label(),
            'origem_geracao' => $propina->origem_geracao->value,
            'origem_geracao_descricao' => $propina->origem_geracao_descricao,
            'motivo_geracao' => $propina->motivo_geracao,
            // §8.1/Q4: retroactiva, ou criada (dia da escola) depois do vencimento.
            'geracao_tardia' => $propina->origem_geracao === OrigemGeracaoPropina::RETROACTIVA
                || ($criadaNaEscola !== null && $criadaNaEscola > $vencimento),
        ];
    }

    private function data(mixed $valor): ?string
    {
        if (! is_string($valor) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $valor, $partes) !== 1) {
            return null;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $valor : null;
    }
}
