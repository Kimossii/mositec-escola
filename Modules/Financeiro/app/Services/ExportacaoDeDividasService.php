<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Exportacao\TabelaExportavel;
use Modules\Estabelecimento\Models\Estabelecimento;

/** A tabela das dívidas para exportar (Excel, PDF…), com os valores na moeda da escola. */
class ExportacaoDeDividasService
{
    public function __construct(private DividasConsultaService $consulta, private MoedaDoTenant $moeda)
    {
    }

    /** @param  array<string, mixed>  $filtros  os da lista de dívidas */
    public function tabela(array $filtros): TabelaExportavel
    {
        $moeda = $this->moeda->atual();
        $maior = fn (int $v): float => $v / $moeda->fator();
        $linhas = $this->consulta->linhas($filtros)
            ->map(fn (array $l) => [
                'aluno' => $l['aluno_nome'], 'numero' => $l['aluno_numero'], 'matricula' => $l['matricula_numero'], 'turma' => $l['turma_rotulo_completo'], 'ano' => $l['ano_lectivo_nome'],
                'propinas' => $maior($l['propinas']), 'multas' => $maior($l['multas']), 'cobrancas' => $maior($l['cobrancas']), 'total' => $maior($l['total']),
                'meses' => $l['meses'], 'desde' => $l['desde'],
            ]);

        return new TabelaExportavel(
            titulo: 'Dívidas',
            colunas: [
                'aluno' => 'Aluno', 'numero' => 'Nº aluno', 'matricula' => 'Matrícula', 'turma' => 'Turma', 'ano' => 'Ano lectivo',
                'propinas' => "Propinas ({$moeda->simbolo})", 'multas' => "Multas ({$moeda->simbolo})", 'cobrancas' => "Produtos/serviços ({$moeda->simbolo})", 'total' => "Total ({$moeda->simbolo})",
                'meses' => 'Meses em dívida', 'desde' => 'Em dívida desde',
            ],
            linhas: $linhas->all(),
            subtitulo: 'Da maior dívida para a menor',
            entidade: Estabelecimento::current()?->nome,
            assinaturas: ['Elaborado por', 'Verificado por', 'O Director'],
        );
    }
}
