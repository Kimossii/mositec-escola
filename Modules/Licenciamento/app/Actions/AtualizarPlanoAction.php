<?php

namespace Modules\Licenciamento\Actions;

use Modules\Core\Traits\LogService;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\Exceptions\DadosDePlanoInvalidos;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Services\ValidaDireitosComerciais;

/** Editar um plano não altera as licenças já emitidas (têm snapshot). */
class AtualizarPlanoAction
{
    use LogService;

    public function __construct(private readonly ValidaDireitosComerciais $validador) {}

    public function executar(Plano $plano, PlanoDTO $dto): Plano
    {
        return $this->comLog('Atualizar plano de licença', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($plano, $dto) {
            self::preencher($plano, $dto, $this->validador);
            $plano->save();

            return $plano;
        });
    }

    /**
     * Valida e copia o DTO para o plano (sem gravar). Partilhado com a criação.
     *
     * @throws DadosDePlanoInvalidos
     */
    public static function preencher(Plano $plano, PlanoDTO $dto, ValidaDireitosComerciais $validador): void
    {
        $nome = trim($dto->nome);
        $erros = $validador->erros($dto->limites, $dto->toleranciaDias, $dto->avisoDias, array_values($dto->modulos), $dto->validadeDias);

        if ($nome === '') {
            $erros['nome'] = 'O nome do plano é obrigatório.';
        }

        if ($erros !== []) {
            throw new DadosDePlanoInvalidos($erros);
        }

        $plano->fill([
            'nome' => $nome,
            'descricao' => $dto->descricao,
            'limite_alunos' => $dto->limites['alunos'] ?? null,
            'limite_utilizadores' => $dto->limites['utilizadores'] ?? null,
            'tolerancia_dias' => $dto->toleranciaDias,
            'aviso_dias' => $dto->avisoDias,
            'modulos' => array_values(array_unique($dto->modulos)),
            'validade_dias' => $dto->validadeDias,
            'modalidade' => $dto->modalidade,
        ]);
    }
}
