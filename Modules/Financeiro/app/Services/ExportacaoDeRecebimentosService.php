<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Exportacao\TabelaExportavel;
use Modules\Estabelecimento\Models\Estabelecimento;

/** A tabela dos recebimentos de um período para exportar, com os valores na moeda da escola. */
class ExportacaoDeRecebimentosService
{
    public function __construct(private RelatorioFinanceiroService $relatorio, private MoedaDoTenant $moeda)
    {
    }

    public function tabela(string $de, string $ate): TabelaExportavel
    {
        $moeda = $this->moeda->atual();
        $linhas = array_map(fn (array $l) => [...$l, 'valor' => $l['valor'] / $moeda->fator()], $this->relatorio->recebimentos($de, $ate));

        return new TabelaExportavel(
            titulo: 'Recebimentos',
            colunas: ['numero' => 'Nº', 'data' => 'Data', 'aluno' => 'Aluno', 'numero_aluno' => 'Nº aluno', 'matricula' => 'Matrícula', 'metodo' => 'Método', 'referencia' => 'Referência', 'valor' => "Valor ({$moeda->simbolo})"],
            linhas: $linhas,
            subtitulo: "Período {$de} a {$ate} · só pagamentos confirmados",
            entidade: Estabelecimento::current()?->nome,
            assinaturas: ['Elaborado por', 'Verificado por', 'O Director'],
        );
    }
}
