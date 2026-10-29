<?php

namespace Modules\Curso\Importacao;

use Modules\Core\Importacao\ImportadorDeEntidade;
use Modules\Curso\Actions\CriarCursoAction;
use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;

/** Importação de cursos: código e nome únicos na escola; cria pela mesma Action do formulário. */
class CursoImportador extends ImportadorDeEntidade
{
    public function chave(): string
    {
        return 'cursos';
    }

    public function titulo(): string
    {
        return 'Cursos';
    }

    public function permissao(): string
    {
        return 'curso.criar';
    }

    public function colunas(): array
    {
        return [
            'codigo' => ['rotulo' => 'Código', 'obrigatoria' => true, 'exemplo' => 'INF', 'ajuda' => 'Identifica o curso de forma única (máx. 50 caracteres). Se já existir, a linha é ignorada.'],
            'nome' => ['rotulo' => 'Nome', 'obrigatoria' => true, 'exemplo' => 'Informática de Gestão', 'ajuda' => 'Nome completo, único na escola (máx. 255 caracteres).'],
            'descricao' => ['rotulo' => 'Descrição', 'obrigatoria' => false, 'exemplo' => '', 'ajuda' => 'Opcional.'],
        ];
    }

    public function regras(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:50'],
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function colunaChave(): string
    {
        return 'codigo';
    }

    public function colunasUnicas(): array
    {
        return ['nome'];
    }

    protected function modelo(): string
    {
        return Curso::class;
    }

    public function criar(array $dados): int
    {
        return app(CriarCursoAction::class)->executar(new CursoDTO($dados['codigo'], $dados['nome'], $dados['descricao'] ?? null))->id;
    }
}
