<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\CriarCobrancaAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\MomentoObrigatorio;
use Modules\Financeiro\Enums\OrigemCobranca;
use Modules\Financeiro\Models\CatalogoAlvo;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\CobrancaItem;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Models\Servico;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

/**
 * Cobra, ao activar uma matrícula, os produtos e serviços OBRIGATÓRIOS do catálogo que lhe cabem (alvos por nível ou
 * curso, «só alunos novos», e o momento: ao matricular, uma vez por ano ou uma só vez). Só com a regra «gerar os
 * obrigatórios ao matricular» ligada. Uma cobrança por matrícula com todas as linhas; idempotente. Produtos com
 * tamanhos ou sem stock ficam de fora (exigem escolha ou reposição): cobram-se à mão.
 */
class GerarCobrancasObrigatorias
{
    public function __construct(private CriarCobrancaAction $criar)
    {
    }

    public function executar(int $matriculaId): ?Cobranca
    {
        if (! (RegraCobranca::query()->first()?->gerar_obrigatorios_ao_matricular ?? true)) {
            return null;
        }

        $matricula = Matricula::query()->with('turma')->find($matriculaId);

        if ($matricula === null || $matricula->estado !== EstadoMatriculaEnum::ACTIVA) {
            return null;
        }

        $itens = [];

        foreach ($this->candidatos() as $candidato) {
            if ($this->cabe($candidato['modelo'], $candidato['tipo'], $matricula) && ! $this->jaCobrado($candidato['modelo'], $candidato['tipo'], $matricula)) {
                $itens[] = ['tipo' => $candidato['tipo'], 'id' => (int) $candidato['modelo']->id, 'quantidade' => 1];
            }
        }

        if ($itens === []) {
            return null;
        }

        return $this->criar->executar($matricula, $itens, null, 'Obrigatório ao matricular', OrigemCobranca::OBRIGATORIO);
    }

    /** @return list<array{tipo: string, modelo: Produto|Servico}> */
    private function candidatos(): array
    {
        $produtos = Produto::query()->where('estado', Estado::ATIVO->value)->where('obrigatorio', true)
            ->where('tem_variantes', false)
            ->where(fn ($q) => $q->where('controla_stock', false)->orWhere('stock_quantidade', '>=', 1))
            ->get()->map(fn ($p) => ['tipo' => 'produto', 'modelo' => $p]);
        $servicos = Servico::query()->where('estado', Estado::ATIVO->value)->where('obrigatorio', true)
            ->get()->map(fn ($s) => ['tipo' => 'servico', 'modelo' => $s]);

        return $produtos->concat($servicos)->values()->all();
    }

    private function cabe(Produto|Servico $item, string $tipo, Matricula $matricula): bool
    {
        if ($item->apenas_alunos_novos && Matricula::query()->where('aluno_id', $matricula->aluno_id)->where('id', '<', $matricula->id)->exists()) {
            return false;
        }

        $alvos = CatalogoAlvo::query()->where('item_tipo', $tipo)->where('item_id', $item->id)->get();

        if ($alvos->isEmpty()) {
            return true;
        }

        return $alvos->contains(fn (CatalogoAlvo $a) => ($a->nivel_academico_id !== null && (int) $a->nivel_academico_id === (int) $matricula->turma?->nivel_academico_id)
            || ($a->curso_id !== null && (int) $a->curso_id === (int) $matricula->turma?->curso_id));
    }

    private function jaCobrado(Produto|Servico $item, string $tipo, Matricula $matricula): bool
    {
        $coluna = $tipo === 'produto' ? 'produto_id' : 'servico_id';

        return CobrancaItem::query()
            ->where($coluna, $item->id)
            ->whereHas('cobranca', function ($c) use ($item, $matricula) {
                $c->where('aluno_id', $matricula->aluno_id)->whereNotIn('estado', [EstadoCobranca::CANCELADA->value, EstadoCobranca::ANULADA->value]);

                match ($item->momento_obrigatorio) {
                    MomentoObrigatorio::UMA_SO_VEZ => null,
                    MomentoObrigatorio::UMA_VEZ_POR_ANO => $c->whereIn('matricula_id', Matricula::query()->where('ano_lectivo_id', $matricula->ano_lectivo_id)->select('id')),
                    default => $c->where('matricula_id', $matricula->id),
                };
            })
            ->exists();
    }
}
