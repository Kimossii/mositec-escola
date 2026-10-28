<?php

namespace Modules\Usuario\Services;

use Modules\Core\Exportacao\Exportador;
use Modules\Core\Exportacao\TabelaExportavel;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Enums\Perfil;

class ExportacaoDeUtilizadoresService
{
    /** Perfis que se podem exportar, pelo mesmo nome que têm no endereço da lista. */
    public const PERFIS = [
        'alunos' => Perfil::ALUNO,
        'professores' => Perfil::PROFESSOR,
        'funcionarios' => Perfil::FUNCIONARIO,
        'administradores' => Perfil::ADMIN_ESCOLA,
        'encarregados' => Perfil::ENCARREGADO,
    ];

    private const TITULOS = [
        'alunos' => 'Utilizadores — Alunos',
        'professores' => 'Utilizadores — Professores',
        'funcionarios' => 'Utilizadores — Funcionários',
        'administradores' => 'Utilizadores — Administradores',
        'encarregados' => 'Utilizadores — Encarregados',
    ];

    public function __construct(private UsuarioConsultaService $consulta)
    {
    }

    /** @param  array{pesquisa?: ?string, estado?: ?string}  $filtros */
    public function tabela(?string $perfil, array $filtros, bool $incluirAdministradores = true): TabelaExportavel
    {
        $estado = ($filtros['estado'] ?? '') === '' ? 'todos os estados' : ((string) $filtros['estado'] === '1' ? 'só ativos' : 'só inativos');
        $pesquisa = trim((string) ($filtros['pesquisa'] ?? ''));

        return new TabelaExportavel(
            titulo: self::TITULOS[$perfil] ?? 'Utilizadores',
            colunas: ['nome' => 'Nome', 'email' => 'Email', 'matricula' => 'Nº de matrícula', 'perfis' => 'Perfil', 'estado' => 'Estado', 'criado_em' => 'Criado em'],
            linhas: $this->consulta->paraExportacao(self::PERFIS[$perfil] ?? null, $filtros, Exportador::MAXIMO_LINHAS + 1, $incluirAdministradores),
            subtitulo: 'Filtro: '.$estado.($pesquisa !== '' ? ', pesquisa «'.$pesquisa.'»' : ''),
            entidade: Estabelecimento::current()?->nome,
            assinaturas: ['Elaborado por', 'Aprovado por'],
        );
    }
}
