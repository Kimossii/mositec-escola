<?php

namespace Modules\Docente\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Traits\LogService;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;

/**
 * Decisão E10: um docente com qualquer atribuição (em curso, terminada ou eliminada) não se elimina, porque o histórico
 * de docência depende dele; o dia-a-dia é desactivar. Sem atribuições apaga-se, e a pessoa vai com ele quando mais
 * ninguém a usa (conta, aluno ou documentos).
 */
class EliminarDocenteAction
{
    use LogService;

    public const MENSAGEM_COM_ATRIBUICOES = 'Este docente tem atribuições registadas (em curso, terminadas ou eliminadas) e não pode ser eliminado. Desactive-o para deixar de o atribuir a novas turmas.';

    public const MENSAGEM_COM_ACESSO = 'Este docente tem acesso ao sistema; desactive ou elimine primeiro a conta de utilizador.';

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Docente $docente): void
    {
        $this->comLog('Eliminar docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($docente));
    }

    private function executarSemRegisto(Docente $docente): void
    {
        DB::transaction(function () use ($docente) {
            $actual = Docente::query()->whereKey($docente->getKey())->lockForUpdate()->firstOrFail();

            if (AtribuicaoDocente::withTrashed()->where('docente_id', $actual->id)->exists()) {
                throw ValidationException::withMessages(['docente' => self::MENSAGEM_COM_ATRIBUICOES]);
            }

            if ($actual->temAcesso()) {
                throw ValidationException::withMessages(['docente' => self::MENSAGEM_COM_ACESSO]);
            }

            $pessoaId = (int) $actual->dados_pessoa_id;
            $actual->delete();

            if ($this->pessoaSemOutrosUsos($pessoaId)) {
                DadosPessoa::query()->whereKey($pessoaId)->delete();
            }
        });
    }

    private function pessoaSemOutrosUsos(int $pessoaId): bool
    {
        return ! User::query()->where('dados_pessoa_id', $pessoaId)->exists()
            && ! Aluno::query()->where('dados_pessoa_id', $pessoaId)->exists()
            && ! DadosPessoa::query()->whereKey($pessoaId)->whereHas('documentos')->exists();
    }
}
