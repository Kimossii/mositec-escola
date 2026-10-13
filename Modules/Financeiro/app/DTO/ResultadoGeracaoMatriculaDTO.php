<?php

namespace Modules\Financeiro\DTO;

use Modules\Matricula\Models\Matricula;

/**
 * O que a geração fez (ou faria, na pré-visualização) para UMA matrícula. Cada item de `criadas` traz os
 * dados a gravar e a mostrar; depois de gravado ganha `id`. Se a transacção da matrícula falhar, o
 * serviço deita este objecto fora e devolve um novo só com o erro (nada desta matrícula ficou gravado).
 */
final class ResultadoGeracaoMatriculaDTO
{
    /** @var list<array<string, mixed>> */
    private array $criadas = [];

    /** @var list<array{periodo: ?string, plano: ?string, motivo: string}> */
    private array $ignoradas = [];

    /** @var list<string> */
    private array $conflitos = [];

    /** @var list<string> */
    private array $semPlano = [];

    /** @var list<string> */
    private array $erros = [];

    public function __construct(
        public readonly int $matricula_id,
        public readonly ?string $numero_registo = null,
        public readonly ?string $aluno_nome = null,
        public readonly ?string $aluno_numero = null,
        public readonly ?string $turma_nome = null,
    ) {
    }

    public static function de(Matricula $matricula): self
    {
        return new self(
            matricula_id: (int) $matricula->id,
            numero_registo: $matricula->numero_registo_matricula,
            aluno_nome: $matricula->aluno?->dadosPessoa?->nome_completo,
            aluno_numero: $matricula->aluno?->numero_matricula,
            turma_nome: $matricula->turma?->nome,
        );
    }

    /**
     * @param  array<string, mixed>  $propina
     */
    public function aCriar(array $propina): void
    {
        $this->criadas[] = $propina;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function criadas(): array
    {
        return $this->criadas;
    }

    public function criada(int $indice, int $propinaId): void
    {
        $this->criadas[$indice]['id'] = $propinaId;
    }

    public function ignorada(string $motivo, ?string $periodo = null, ?string $plano = null): void
    {
        $this->ignoradas[] = ['periodo' => $periodo, 'plano' => $plano, 'motivo' => $motivo];
    }

    public function conflito(string $descricao): void
    {
        $this->conflitos[] = $descricao;
    }

    public function semPlano(string $competencia): void
    {
        $this->semPlano[] = $competencia;
    }

    public function erro(string $mensagem): void
    {
        $this->erros[] = $mensagem;
    }

    /**
     * @return array{criadas: int, ignoradas: int, conflitos: int, sem_plano: int, erros: int}
     */
    public function contagens(): array
    {
        return [
            'criadas' => count($this->criadas),
            'ignoradas' => count($this->ignoradas),
            'conflitos' => count($this->conflitos),
            'sem_plano' => count($this->semPlano),
            'erros' => count($this->erros),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'matricula_id' => $this->matricula_id,
            'numero_registo' => $this->numero_registo,
            'aluno_nome' => $this->aluno_nome,
            'aluno_numero' => $this->aluno_numero,
            'turma_nome' => $this->turma_nome,
            'criadas' => $this->criadas,
            'ignoradas' => $this->ignoradas,
            'conflitos' => $this->conflitos,
            'sem_plano' => $this->semPlano,
            'erros' => $this->erros,
        ];
    }
}
