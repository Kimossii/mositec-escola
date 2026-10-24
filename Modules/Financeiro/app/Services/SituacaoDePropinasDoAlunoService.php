<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Contracts\SituacaoDePropinasDoAluno;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\OrigemCobranca;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Models\Acordo;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\Multa;
use Modules\Financeiro\Support\ProgressoDoAcordo;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

/**
 * Implementação, no módulo Financeiro, do contrato usado pelo Aluno. A situação é a da matrícula não
 * terminal (Pendente/Activa) mais recente do aluno no ano lectivo activo; os totais só contam propinas
 * activas (Em Aberto, Parcialmente Paga ou Paga) — canceladas e anuladas ficam de fora. Nunca escreve.
 */
class SituacaoDePropinasDoAlunoService implements SituacaoDePropinasDoAluno
{
    public function __construct(
        private RelogioDoTenant $relogio,
        private MoedaDoTenant $moedaDoTenant,
        private ProgressoDoAcordo $progressoDoAcordo,
    ) {
    }

    public function moeda(): array
    {
        return $this->moedaDoTenant->atual()->paraFrontend();
    }

    public function doAluno(int $alunoId): array
    {
        $matricula = $this->matriculaActiva($alunoId);
        $cobrancas = $this->cobrancasEmAberto($alunoId);
        $acordo = $this->acordoActivo($alunoId);

        if ($matricula === null) {
            return $this->vazio() + ['cobrancas' => $cobrancas, 'acordo' => $acordo];
        }

        $hoje = $this->relogio->hoje();
        $propinas = $this->propinasDaMatricula($matricula->id);

        return [
            'tem_matricula_activa' => true,
            'matricula_id' => $matricula->id,
            ...$this->totais($propinas, $hoje),
            'periodos' => $propinas->map(fn (Propina $propina) => $this->periodo($propina, $hoje))->values()->all(),
            'cobrancas' => $cobrancas,
            'acordo' => $acordo,
        ];
    }

    /**
     * O acordo de pagamento activo do aluno (se houver), com o que falta e a próxima prestação, para a ficha.
     *
     * @return array{id: int, em_falta: int, em_atraso: bool, proxima: ?array{ordem: int, data_vencimento: string, em_falta: int}}|null
     */
    private function acordoActivo(int $alunoId): ?array
    {
        $acordo = Acordo::query()->where('aluno_id', $alunoId)->where('estado', EstadoAcordo::ACTIVO->value)->latest('id')->first();

        if ($acordo === null) {
            return null;
        }

        $p = $this->progressoDoAcordo->de($acordo);

        return ['id' => $acordo->id, 'em_falta' => $p['em_falta'], 'em_atraso' => $p['em_atraso'], 'proxima' => $p['proxima']];
    }

    /**
     * Produtos e serviços por pagar do aluno (todas as matrículas): obrigatórios e opcionais, para a ficha e as dívidas.
     *
     * @return list<array{id: int, descricao: string, total: int, saldo: int, obrigatoria: bool, data_limite: ?string, estado: int, estado_descricao: string}>
     */
    private function cobrancasEmAberto(int $alunoId): array
    {
        return Cobranca::query()
            ->where('aluno_id', $alunoId)
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->with('itens')
            ->orderBy('id')
            ->get()
            ->map(fn (Cobranca $c) => [
                'id' => $c->id,
                'descricao' => $c->itens->map(fn ($i) => $i->nome . ($i->quantidade > 1 ? " × {$i->quantidade}" : ''))->implode(', '),
                'total' => $c->total->unidadesMenores(),
                'saldo' => $c->saldo(),
                'obrigatoria' => $c->origem === OrigemCobranca::OBRIGATORIO,
                'data_limite' => $c->data_limite?->toDateString(),
                'estado' => $c->estado->value,
                'estado_descricao' => $c->estado_descricao,
            ])
            ->values()
            ->all();
    }

    public function paraListaDeAlunos(array $alunoIds): array
    {
        $alunoIds = array_values(array_filter(array_map('intval', $alunoIds)));

        $resultado = [];

        foreach ($alunoIds as $alunoId) {
            $resultado[$alunoId] = $this->resumoVazio();
        }

        if ($alunoIds === []) {
            return $resultado;
        }

        $anoLectivoId = AnoLectivo::current()?->id;

        if ($anoLectivoId === null) {
            return $resultado;
        }

        $matriculaPorAluno = [];

        foreach (Matricula::query()
            ->whereIn('aluno_id', $alunoIds)
            ->where('ano_lectivo_id', $anoLectivoId)
            ->whereIn('estado', [EstadoMatriculaEnum::PENDENTE->value, EstadoMatriculaEnum::ACTIVA->value])
            ->orderByDesc('data_matricula')
            ->orderByDesc('id')
            ->get(['id', 'aluno_id']) as $matricula) {
            $matriculaPorAluno[$matricula->aluno_id] ??= $matricula->id;
        }

        if ($matriculaPorAluno === []) {
            return $resultado;
        }

        $hoje = $this->relogio->hoje();
        $totaisPorMatricula = $this->agregarPorMatricula(
            Propina::query()->activas()->with('multa')->whereIn('matricula_id', array_values($matriculaPorAluno))->get(),
            $hoje,
        );

        foreach ($matriculaPorAluno as $alunoId => $matriculaId) {
            $totais = $totaisPorMatricula[$matriculaId] ?? ['capital' => 0, 'multas' => 0, 'em_atraso' => 0];

            $resultado[$alunoId] = [
                'tem_matricula_activa' => true,
                'matricula_id' => $matriculaId,
                'capital_em_divida' => $totais['capital'],
                'multas_pendentes' => $totais['multas'],
                'total_em_divida' => $totais['capital'] + $totais['multas'],
                'propinas_em_atraso' => $totais['em_atraso'],
                'estado' => $this->estadoResumo($totais['em_atraso']),
            ];
        }

        return $resultado;
    }

    private function matriculaActiva(int $alunoId): ?Matricula
    {
        $anoLectivoId = AnoLectivo::current()?->id;

        if ($anoLectivoId === null) {
            return null;
        }

        return Matricula::query()
            ->where('aluno_id', $alunoId)
            ->where('ano_lectivo_id', $anoLectivoId)
            ->whereIn('estado', [EstadoMatriculaEnum::PENDENTE->value, EstadoMatriculaEnum::ACTIVA->value])
            ->orderByDesc('data_matricula')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, Propina> */
    private function propinasDaMatricula(int $matriculaId): Collection
    {
        return Propina::query()
            ->activas()
            ->with('multa')
            ->where('matricula_id', $matriculaId)
            ->orderBy('ordem')
            ->get();
    }

    /**
     * @param  Collection<int, Propina>  $propinas
     * @return array{capital_em_divida: int, multas_pendentes: int, total_em_divida: int, propinas_em_atraso: int, proximo_vencimento: ?string, estado: string}
     */
    private function totais(Collection $propinas, CarbonImmutable $hoje): array
    {
        $capital = 0;
        $multas = 0;
        $emAtraso = 0;
        $proximo = null;
        $dia = $hoje->toDateString();

        foreach ($propinas as $propina) {
            $saldo = $propina->saldo()->unidadesMenores();
            $capital += $saldo;
            $multas += $propina->multaPendente();

            if ($propina->estadoResolvido($hoje) === EstadoCobranca::EM_ATRASO) {
                $emAtraso++;
            }

            if ($saldo > 0 && $propina->data_vencimento->toDateString() >= $dia) {
                $vencimento = $propina->data_vencimento->toDateString();
                $proximo = $proximo === null || $vencimento < $proximo ? $vencimento : $proximo;
            }
        }

        return [
            'capital_em_divida' => $capital,
            'multas_pendentes' => $multas,
            'total_em_divida' => $capital + $multas,
            'propinas_em_atraso' => $emAtraso,
            'proximo_vencimento' => $proximo,
            'estado' => $this->estadoResumo($emAtraso),
        ];
    }

    /**
     * @param  Collection<int, Propina>  $propinas
     * @return array<int, array{capital: int, multas: int, em_atraso: int}>
     */
    private function agregarPorMatricula(Collection $propinas, CarbonImmutable $hoje): array
    {
        $resultado = [];

        foreach ($propinas as $propina) {
            $matriculaId = $propina->matricula_id;
            $resultado[$matriculaId] ??= ['capital' => 0, 'multas' => 0, 'em_atraso' => 0];

            $resultado[$matriculaId]['capital'] += $propina->saldo()->unidadesMenores();
            $resultado[$matriculaId]['multas'] += $propina->multaPendente();

            if ($propina->estadoResolvido($hoje) === EstadoCobranca::EM_ATRASO) {
                $resultado[$matriculaId]['em_atraso']++;
            }
        }

        return $resultado;
    }

    /** @return array{periodo: string, valor: int, pago: int, saldo: int, multa_pendente: int, estado: int, estado_descricao: string} */
    private function periodo(Propina $propina, CarbonImmutable $hoje): array
    {
        $estado = $propina->estadoResolvido($hoje);

        return [
            'propina_id' => $propina->id,
            'periodo' => RotuloDePeriodo::periodo($propina->periodo_inicio->toDateString(), $propina->periodo_fim->toDateString()),
            'valor' => $propina->valor->unidadesMenores(),
            'pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
            'multa_pendente' => $propina->multaPendente(),
            // Multa aplicada (qualquer que seja o estado do pagamento) e quanto dela já foi paga: a coluna mostra as duas.
            'multa_valor' => $this->multaValor($propina),
            'multa_regra' => $this->multaRegra($propina),
            'multa_paga' => $propina->multa === null || $propina->multa->estado === EstadoMulta::ANULADA ? 0 : $propina->multa->valor_pago->unidadesMenores(),
            'estado' => $estado->value,
            'estado_descricao' => $estado->label(),
        ];
    }

    private function multaRegra(Propina $propina): ?string
    {
        $multa = $propina->multa;

        return $multa === null || $multa->tipo === null || $multa->estado === EstadoMulta::ANULADA
            ? null
            : Multa::descreverRegra($multa->tipo, (int) $multa->valor_regra, $multa->escalao_ordem, $this->moedaDoTenant->atual());
    }

    private function multaValor(Propina $propina): int
    {
        $multa = $propina->multa;

        return $multa === null || $multa->estado === EstadoMulta::ANULADA || $multa->estado === EstadoMulta::ISENTA ? 0 : $multa->valor->unidadesMenores();
    }

    private function vazio(): array
    {
        return [
            'tem_matricula_activa' => false,
            'matricula_id' => null,
            'capital_em_divida' => 0,
            'multas_pendentes' => 0,
            'total_em_divida' => 0,
            'propinas_em_atraso' => 0,
            'proximo_vencimento' => null,
            'estado' => 'Sem matrícula activa',
            'periodos' => [],
        ];
    }

    private function resumoVazio(): array
    {
        return [
            'tem_matricula_activa' => false,
            'matricula_id' => null,
            'capital_em_divida' => 0,
            'multas_pendentes' => 0,
            'total_em_divida' => 0,
            'propinas_em_atraso' => 0,
            'estado' => '',
        ];
    }

    private function estadoResumo(int $emAtraso): string
    {
        return $emAtraso === 0 ? 'Em dia' : "{$emAtraso} em atraso";
    }
}
