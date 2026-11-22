<?php

namespace Modules\Aluno\Actions;

use Modules\Aluno\Models\Aluno;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Core\Licenciamento\Contracts\GarantiaDeLimites;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Core\Traits\LogService;

class AlterarEstadoAlunoAction
{
    use LogService;

    public function __construct(private GarantiaDeLimites $limites) {}

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Aluno $aluno, Estado $novoEstado): Aluno
    {
        return $this->comLog('Alterar estado aluno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($aluno, $novoEstado));
    }

    private function executarSemRegisto(Aluno $aluno, Estado $novoEstado): Aluno
    {
        return DB::transaction(function () use ($aluno, $novoEstado) {
            // Reactivar ocupa vaga outra vez: mesma verificação da criação.
            if ($novoEstado === Estado::ATIVO && (int) $aluno->estado !== Estado::ATIVO->value) {
                $this->limites->garantir(Recurso::ALUNOS);
            }

            $aluno->estado = $novoEstado->value;
            $aluno->save();

            return $aluno->fresh();
        });
    }
}
