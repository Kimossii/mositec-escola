<?php

namespace Modules\Disciplina\Importacao;

use Modules\Core\Importacao\ImportadorDeEntidade;
use Modules\Disciplina\Actions\CriarDisciplinaAction;
use Modules\Disciplina\DTO\DisciplinaDTO;
use Modules\Disciplina\Models\Disciplina;

/** Importação de disciplinas: código e nome únicos na escola; cria pela mesma Action do formulário. */
class DisciplinaImportador extends ImportadorDeEntidade
{
    public function chave(): string
    {
        return 'disciplinas';
    }

    public function titulo(): string
    {
        return 'Disciplinas';
    }

    public function permissao(): string
    {
        return 'disciplina.criar';
    }

    public function colunas(): array
    {
        return [
            'codigo' => ['rotulo' => 'Código', 'obrigatoria' => true, 'exemplo' => 'MAT', 'ajuda' => 'Identifica a disciplina de forma única (máx. 50 caracteres). Se já existir, a linha é ignorada.'],
            'nome' => ['rotulo' => 'Nome', 'obrigatoria' => true, 'exemplo' => 'Matemática', 'ajuda' => 'Nome completo, único na escola (máx. 255 caracteres).'],
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
        return Disciplina::class;
    }

    public function criar(array $dados): int
    {
        return app(CriarDisciplinaAction::class)->executar(new DisciplinaDTO($dados['codigo'], $dados['nome'], $dados['descricao'] ?? null))->id;
    }
}
