<?php

namespace Modules\Financeiro\Tests\Concerns;

use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

trait ComDadosAcademicosFinanceiro
{
    protected function anoLectivo(string $nome = '2026/2027', string $inicio = '2026-09-01', string $fim = '2027-07-31'): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'nome' => $nome,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
    }

    protected function nivel(string $codigo): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'codigo' => $codigo,
            'nome' => "Nível {$codigo}",
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
            'ordem' => 1,
        ]);
    }

    protected function curso(string $codigo): Curso
    {
        return Curso::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'codigo' => $codigo,
            'nome' => "Curso {$codigo}",
        ]);
    }

    protected function turno(string $nome): Turno
    {
        return Turno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'nome' => $nome,
        ]);
    }

    protected function turma(AnoLectivo $ano, NivelAcademico $nivel, ?Curso $curso = null, ?Turno $turno = null): Turma
    {
        $sufixo = uniqid();

        return Turma::create([
            'ano_lectivo_id' => $ano->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso?->id,
            'turno_id' => $turno?->id,
            'codigo' => "TU{$sufixo}",
            'nome' => "Turma {$sufixo}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  list<array{nivel?: ?NivelAcademico, curso?: ?Curso, turno?: ?Turno, turma?: ?Turma}>  $alvos
     */
    protected function plano(AnoLectivo $ano, string $nome, array $atributos = [], array $alvos = []): PlanoPropina
    {
        $plano = PlanoPropina::create(array_merge([
            'ano_lectivo_id' => $ano->id,
            'nome' => $nome,
            'periodicidade' => Periodicidade::MENSAL,
            'intervalo_meses' => 1,
            'valor' => Dinheiro::deUnidadesMenores(2_500_000),
            'mes_inicio' => 9,
            'mes_fim' => 6,
        ], $atributos));

        foreach ($alvos as $alvo) {
            PlanoPropinaAlvo::create([
                'plano_propina_id' => $plano->id,
                'nivel_academico_id' => ($alvo['nivel'] ?? null)?->id,
                'curso_id' => ($alvo['curso'] ?? null)?->id,
                'turno_id' => ($alvo['turno'] ?? null)?->id,
                'turma_id' => ($alvo['turma'] ?? null)?->id,
            ]);
        }

        return $plano;
    }
}
