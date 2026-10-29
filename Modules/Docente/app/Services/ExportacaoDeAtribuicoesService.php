<?php

namespace Modules\Docente\Services;

use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Exportacao\Exportador;
use Modules\Core\Exportacao\TabelaExportavel;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Monta a tabela exportável das atribuições docentes, com os filtros e a referência de data da lista. */
class ExportacaoDeAtribuicoesService
{
    public function __construct(private AtribuicaoDocenteConsultaService $consulta)
    {
    }

    /** @param  array<string, mixed>  $filtros  os da lista (sem `ano_lectivo_id`, usa-se o ano activo) */
    public function tabela(array $filtros): TabelaExportavel
    {
        $filtros['ano_lectivo_id'] = (int) ($filtros['ano_lectivo_id'] ?? 0) ?: $this->consulta->anoLectivoPadrao();
        $ano = $filtros['ano_lectivo_id'] ? AnoLectivo::query()->find($filtros['ano_lectivo_id']) : null;
        $pesquisa = trim((string) ($filtros['pesquisa'] ?? ''));

        return new TabelaExportavel(
            titulo: 'Atribuições docentes',
            colunas: ['professor' => 'Professor', 'turma' => 'Turma', 'disciplina' => 'Disciplina', 'papel' => 'Papel', 'inicio' => 'Início', 'fim' => 'Fim', 'estado' => 'Estado'],
            linhas: $this->consulta->paraExportacao($filtros, Exportador::MAXIMO_LINHAS + 1),
            subtitulo: 'Ano lectivo: '.($ano?->nome ?? 'todos').($pesquisa !== '' ? ', pesquisa «'.$pesquisa.'»' : ''),
            entidade: Estabelecimento::current()?->nome,
            assinaturas: ['Elaborado por', 'Aprovado por'],
        );
    }
}
