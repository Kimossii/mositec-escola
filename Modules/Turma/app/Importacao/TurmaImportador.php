<?php

namespace Modules\Turma\Importacao;

use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Importacao\ImportadorDeEntidade;
use Modules\Curso\Models\Curso;
use Modules\Turma\Actions\CriarTurmaAction;
use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

/**
 * Importação de turmas. Referencia ano lectivo, nível, curso e turno pelo nome/código que a escola já tem (nunca por id).
 * A turma é única por ano lectivo + código; cria pela mesma Action do formulário.
 */
class TurmaImportador extends ImportadorDeEntidade
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $mapas = null;

    public function chave(): string
    {
        return 'turmas';
    }

    public function titulo(): string
    {
        return 'Turmas';
    }

    public function permissao(): string
    {
        return 'turmas.criar';
    }

    public function colunas(): array
    {
        return [
            'codigo' => ['rotulo' => 'Código', 'obrigatoria' => true, 'exemplo' => '10A-2026', 'ajuda' => 'Único dentro do ano lectivo (máx. 50 caracteres). Se já existir nesse ano, a linha é ignorada.'],
            'nome' => ['rotulo' => 'Nome', 'obrigatoria' => true, 'exemplo' => '10.ª A', 'ajuda' => 'Como a turma aparece no sistema (máx. 255 caracteres).'],
            'ano_lectivo' => ['rotulo' => 'Ano lectivo', 'obrigatoria' => true, 'exemplo' => '2026/2027', 'ajuda' => 'Nome exacto de um ano lectivo já criado.'],
            'nivel_academico' => ['rotulo' => 'Nível académico', 'obrigatoria' => true, 'exemplo' => '10ª Classe', 'ajuda' => 'Nome ou código de um nível já criado.'],
            'curso' => ['rotulo' => 'Curso', 'obrigatoria' => false, 'exemplo' => 'INF', 'ajuda' => 'Código de um curso já criado. Obrigatório nos níveis do ensino secundário e superior.'],
            'turno' => ['rotulo' => 'Turno', 'obrigatoria' => false, 'exemplo' => 'Manhã', 'ajuda' => 'Nome de um turno já criado. Opcional.'],
        ];
    }

    public function regras(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:50'],
            'nome' => ['required', 'string', 'max:255'],
            'ano_lectivo' => ['required', 'string'],
            'nivel_academico' => ['required', 'string'],
            'curso' => ['nullable', 'string'],
            'turno' => ['nullable', 'string'],
        ];
    }

    public function colunaChave(): string
    {
        return 'codigo';
    }

    protected function modelo(): string
    {
        return Turma::class;
    }

    public function chaveDe(array $dados): ?string
    {
        $ano = $this->procurar('anos', $dados['ano_lectivo'] ?? null);
        $codigo = $dados['codigo'] ?? null;

        return $ano === null || $codigo === null ? null : "{$ano}|{$codigo}";
    }

    public function rotuloDaChave(): string
    {
        return 'Turma (código neste ano lectivo)';
    }

    public function chavesExistentes(array $bloco): array
    {
        $porAno = [];
        foreach ($bloco as $dados) {
            $ano = $this->procurar('anos', $dados['ano_lectivo'] ?? null);
            if ($ano !== null && isset($dados['codigo'])) {
                $porAno[$ano][] = (string) $dados['codigo'];
            }
        }

        $existentes = [];
        foreach ($porAno as $ano => $codigos) {
            foreach (Turma::query()->where('ano_lectivo_id', $ano)->whereIn('codigo', $codigos)->pluck('codigo') as $codigo) {
                $existentes[] = "{$ano}|{$codigo}";
            }
        }

        return $existentes;
    }

    public function validarLinha(array $dados): array
    {
        $erros = [];

        if ($this->procurar('anos', $dados['ano_lectivo']) === null) {
            $erros[] = "Ano lectivo «{$dados['ano_lectivo']}» não existe.";
        }

        $nivelId = $this->procurar('niveis', $dados['nivel_academico']);
        if ($nivelId === null) {
            $erros[] = "Nível académico «{$dados['nivel_academico']}» não existe.";
        }

        $curso = $dados['curso'] ?? null;
        if ($curso !== null && $this->procurar('cursos', $curso) === null) {
            $erros[] = "Curso «{$curso}» não existe.";
        } elseif ($curso === null && $nivelId !== null && $this->mapas()['exigeCurso'][$nivelId]) {
            $erros[] = 'O curso é obrigatório neste nível académico.';
        }

        $turno = $dados['turno'] ?? null;
        if ($turno !== null && $this->procurar('turnos', $turno) === null) {
            $erros[] = "Turno «{$turno}» não existe.";
        }

        return $erros;
    }

    public function criar(array $dados): int
    {
        return app(CriarTurmaAction::class)->executar(new TurmaDTO(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            ano_lectivo_id: $this->procurar('anos', $dados['ano_lectivo']),
            nivel_academico_id: $this->procurar('niveis', $dados['nivel_academico']),
            curso_id: isset($dados['curso']) ? $this->procurar('cursos', $dados['curso']) : null,
            turno_id: isset($dados['turno']) ? $this->procurar('turnos', $dados['turno']) : null,
        ))->id;
    }

    /** id da entidade com este nome/código (sem distinguir maiúsculas), ou null. */
    private function procurar(string $mapa, ?string $valor): ?int
    {
        return $valor === null ? null : ($this->mapas()[$mapa][mb_strtolower(trim($valor))] ?? null);
    }

    /** Lido uma só vez por importação: a escola tem poucas centenas de anos, níveis, cursos e turnos. */
    private function mapas(): array
    {
        if ($this->mapas !== null) {
            return $this->mapas;
        }

        $niveis = [];
        $exigeCurso = [];
        foreach (NivelAcademico::query()->get(['id', 'codigo', 'nome', 'etapa_ensino']) as $n) {
            $niveis[mb_strtolower($n->nome)] = $n->id;
            $niveis[mb_strtolower($n->codigo)] = $n->id;
            $exigeCurso[$n->id] = (bool) $n->etapa_ensino?->exigeCurso();
        }

        return $this->mapas = [
            'anos' => AnoLectivo::query()->pluck('id', 'nome')->mapWithKeys(fn ($id, $nome) => [mb_strtolower($nome) => $id])->all(),
            'niveis' => $niveis,
            'exigeCurso' => $exigeCurso,
            'cursos' => Curso::query()->pluck('id', 'codigo')->mapWithKeys(fn ($id, $codigo) => [mb_strtolower($codigo) => $id])->all(),
            'turnos' => Turno::query()->pluck('id', 'nome')->mapWithKeys(fn ($id, $nome) => [mb_strtolower($nome) => $id])->all(),
        ];
    }
}
