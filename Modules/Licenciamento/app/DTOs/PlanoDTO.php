<?php

namespace Modules\Licenciamento\DTOs;

use Modules\Licenciamento\Enums\Modalidade;

final readonly class PlanoDTO
{
    /**
     * @param  list<string>  $modulos
     * @param  array<string,?int>  $limites  chaves alunos/utilizadores/estabelecimentos; ausente ou null = sem limite
     */
    public function __construct(
        public string $nome,
        public array $modulos,
        public int $validadeDias,
        public Modalidade $modalidade,
        public array $limites = [],
        public ?int $toleranciaDias = null,
        public ?int $avisoDias = null,
        public ?string $descricao = null,
    ) {}
}
