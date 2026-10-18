<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Contracts\SituacaoDePropinasDoAluno;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
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
    ) {
    }

    public function moeda(): array
    {
        return $this->moedaDoTenant->atual()->paraFrontend();
    }

    public function doAluno(int $alunoId): array
    {
        $matricula = $this->matriculaActiva($alunoId);

        if ($matricula === null) {
            return $this->vazio();
        }

        $hoje = $this->relogio->hoje();
        $propinas = $this->propinasDaMatricula($matricula->id);

        return [
            'tem_matricula_activa' => true,
            'matricula_id' => $matricula->id,
            ...$this->totais($propinas, $hoje),
            'periodos' => $propinas->take(-6)->map(fn (Propina $propina) => $this->periodo($propina, $hoje))->values()->all(),
        ];
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
            'periodo' => RotuloDePeriodo::periodo($propina->periodo_inicio->toDateString(), $propina->periodo_fim->toDateString()),
            'valor' => $propina->valor->unidadesMenores(),
            'pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
            'multa_pendente' => $propina->multaPendente(),
            'estado' => $estado->value,
            'estado_descricao' => $estado->label(),
        ];
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
