<?php

namespace Modules\Docente\Services;

use Modules\Core\Exportacao\Exportador;
use Modules\Core\Exportacao\TabelaExportavel;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Monta a tabela exportável dos docentes, com os filtros da lista. */
class ExportacaoDeDocentesService
{
    public function __construct(private DocenteConsultaService $consulta)
    {
    }

    /** @param  array<string, mixed>  $filtros */
    public function tabela(array $filtros): TabelaExportavel
    {
        $estado = match ((string) ($filtros['estado'] ?? '')) {
            '1' => 'só activos',
            '0' => 'só inactivos',
            default => 'todos os estados',
        };
        $acesso = match ((string) ($filtros['acesso'] ?? '')) {
            'com' => ', com acesso',
            'sem' => ', sem acesso',
            default => '',
        };
        $pesquisa = trim((string) ($filtros['pesquisa'] ?? ''));

        return new TabelaExportavel(
            titulo: 'Docentes',
            colunas: ['nome' => 'Nome', 'bi' => 'Nº de identificação', 'telefone' => 'Telefone', 'email' => 'Email', 'estado' => 'Estado', 'acesso' => 'Com acesso'],
            linhas: $this->consulta->paraExportacao($filtros, Exportador::MAXIMO_LINHAS + 1),
            subtitulo: 'Filtro: '.$estado.$acesso.($pesquisa !== '' ? ', pesquisa «'.$pesquisa.'»' : ''),
            entidade: Estabelecimento::current()?->nome,
            assinaturas: ['Elaborado por', 'Aprovado por'],
        );
    }
}
