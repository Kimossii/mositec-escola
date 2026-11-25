<?php

namespace Modules\Usuario\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Core\Contracts\ProcuraAlunoParaConta;

/** A matrícula oficial de um aluno desta escola (a ficha do aluno; ele não precisa de ter conta de login). */
class MatriculaDeAluno implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || app(ProcuraAlunoParaConta::class)->procurar($value) === null) {
            $fail('Uma das matrículas indicadas não existe.');
        }
    }
}
