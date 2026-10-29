<?php

namespace Modules\Aluno\Importacao;

use Carbon\Carbon;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Importacao\ImportadorDeEntidade;
use Modules\Usuario\Models\DadosPessoa;
use Throwable;

/**
 * Importação das fichas de alunos (sem turma: a turma entra pela importação de Matrículas). Identifica a pessoa pelo
 * número de identificação (BI): se já existe como aluno, a linha é ignorada; se existe como outra pessoa da escola
 * (ex.: docente), reutiliza-se a mesma pessoa. Não cria contas de utilizador nem propinas.
 */
class AlunoImportador extends ImportadorDeEntidade
{
    private const SEXOS = ['m' => DadosPessoa::SEXO_MASCULINO, 'masculino' => DadosPessoa::SEXO_MASCULINO, 'f' => DadosPessoa::SEXO_FEMININO, 'feminino' => DadosPessoa::SEXO_FEMININO];

    public function chave(): string
    {
        return 'alunos';
    }

    public function titulo(): string
    {
        return 'Alunos';
    }

    public function permissao(): string
    {
        return 'aluno.criar';
    }

    public function colunas(): array
    {
        return [
            'nome_completo' => ['rotulo' => 'Nome completo', 'obrigatoria' => true, 'exemplo' => 'Ana Maria Silva', 'ajuda' => 'Máx. 255 caracteres.'],
            'numero_identificacao' => ['rotulo' => 'Nº de identificação (BI)', 'obrigatoria' => true, 'exemplo' => '005678901LA042', 'ajuda' => 'Único por pessoa. Se já existir um aluno com este número, a linha é ignorada.'],
            'data_nascimento' => ['rotulo' => 'Data de nascimento', 'obrigatoria' => true, 'exemplo' => '15/03/2010', 'ajuda' => 'Formato dd/mm/aaaa (ou aaaa-mm-dd). Não pode ser futura.'],
            'sexo' => ['rotulo' => 'Sexo', 'obrigatoria' => false, 'exemplo' => 'F', 'ajuda' => 'M ou F (ou Masculino/Feminino). Em branco: não especificado.'],
            'telefone' => ['rotulo' => 'Telefone', 'obrigatoria' => false, 'exemplo' => '+244 923 000 000', 'ajuda' => 'Opcional.'],
            'telefone_alternativo' => ['rotulo' => 'Telefone alternativo', 'obrigatoria' => false, 'exemplo' => '', 'ajuda' => 'Opcional.'],
            'email' => ['rotulo' => 'E-mail', 'obrigatoria' => false, 'exemplo' => '', 'ajuda' => 'Opcional.'],
        ];
    }

    public function regras(): array
    {
        return [
            'nome_completo' => ['required', 'string', 'max:255'],
            'numero_identificacao' => ['required', 'string', 'max:100'],
            'data_nascimento' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sexo' => ['nullable', 'integer', 'in:0,1,2'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'telefone_alternativo' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    public function mensagens(): array
    {
        return [
            'data_nascimento.date_format' => 'A data de nascimento não é válida (use dd/mm/aaaa).',
            'data_nascimento.before_or_equal' => 'A data de nascimento não pode ser futura.',
            'sexo.in' => 'O sexo deve ser M ou F.',
            'sexo.integer' => 'O sexo deve ser M ou F.',
        ];
    }

    public function colunaChave(): string
    {
        return 'numero_identificacao';
    }

    protected function modelo(): string
    {
        return DadosPessoa::class;
    }

    /** O BI sem espaços e em maiúsculas; a data em aaaa-mm-dd (aceita dd/mm/aaaa e número de série do Excel); o sexo em 0/1/2. */
    public function normalizar(array $dados): array
    {
        $dados = parent::normalizar($dados);

        if (is_string($dados['numero_identificacao'] ?? null)) {
            $dados['numero_identificacao'] = mb_strtoupper(preg_replace('/\s+/', '', $dados['numero_identificacao']));
        }

        $dados['data_nascimento'] = $this->data($dados['data_nascimento'] ?? null);

        $sexo = $dados['sexo'] ?? null;
        if (is_string($sexo)) {
            $dados['sexo'] = self::SEXOS[mb_strtolower($sexo)] ?? $sexo;
        }

        return $dados;
    }

    /** Só as pessoas que já são alunos desta escola: uma pessoa que é só docente não bloqueia, reutiliza-se. */
    public function chavesExistentes(array $bloco): array
    {
        $bis = array_values(array_unique(array_filter(array_map(fn ($d) => $d['numero_identificacao'] ?? null, $bloco))));

        return DadosPessoa::query()->whereIn('numero_identificacao', $bis)
            ->whereIn('id', Aluno::query()->select('dados_pessoa_id'))
            ->pluck('numero_identificacao')->map(fn ($v) => (string) $v)->all();
    }

    public function criar(array $dados): int
    {
        $pessoa = DadosPessoa::query()->where('numero_identificacao', $dados['numero_identificacao'])->first();

        return app(CriarAlunoAction::class)->executar(new AlunoDTO(
            dadosPessoaId: $pessoa?->id,
            nomeCompleto: $dados['nome_completo'],
            email: $dados['email'] ?? null,
            telefone: $dados['telefone'] ?? null,
            dataNascimento: $dados['data_nascimento'],
            sexo: (int) ($dados['sexo'] ?? DadosPessoa::SEXO_NAO_ESPECIFICADO),
            numeroIdentificacao: $dados['numero_identificacao'],
            telefoneAlternativo: $dados['telefone_alternativo'] ?? null,
        ))->id;
    }

    private function data(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }

        try {
            if (is_numeric($valor) && (float) $valor > 1 && (float) $valor < 80000) {
                return Carbon::create(1899, 12, 30)->addDays((int) $valor)->toDateString(); // série de datas do Excel
            }

            if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $valor, $m) === 1) {
                return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : $valor;
            }

            if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $valor, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $valor;
            }
        } catch (Throwable) {
            // cai no valor original: a regra date_format assinala a linha.
        }

        return $valor;
    }
}
