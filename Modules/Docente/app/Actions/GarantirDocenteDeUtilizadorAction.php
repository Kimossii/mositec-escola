<?php

namespace Modules\Docente\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;

/**
 * Garante o registo de docente de um utilizador com perfil Professor (decisão E7). Idempotente por `dados_pessoa_id`:
 * se o utilizador não tem pessoa, cria-a (com o nome e o email da conta) e liga-a; se já há docente para a pessoa, devolve-o.
 */
class GarantirDocenteDeUtilizadorAction
{
    use LogService;

    /** Prefixo do número de identificação provisório de uma pessoa criada a partir de uma conta sem BI conhecido. */
    public const PREFIXO_BI_PROVISORIO = 'SEM-BI-U';

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $utilizador): Docente
    {
        return $this->comLog('Garantir docente de utilizador', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($utilizador));
    }

    private function executarSemRegisto(User $utilizador): Docente
    {
        try {
            return DB::transaction(fn () => $this->garantir($utilizador));
        } catch (UniqueConstraintViolationException) {
            // Pedido concorrente que criou o docente entre a verificação e o insert: o índice único travou-o.
            $pessoaId = $utilizador->fresh()->dados_pessoa_id;
            $docente = $pessoaId !== null ? Docente::query()->where('dados_pessoa_id', $pessoaId)->first() : null;

            if ($docente === null) {
                // Sem pessoa ligada: o número de identificação provisório já existe noutra pessoa.
                throw ValidationException::withMessages([
                    'docente' => 'Já existe uma pessoa com o número de identificação provisório desta conta; corrija os dados da pessoa antes de repetir.',
                ]);
            }

            return $docente;
        }
    }

    private function garantir(User $utilizador): Docente
    {
        $utilizador = User::query()->whereKey($utilizador->getKey())->lockForUpdate()->firstOrFail();

        $pessoaId = $utilizador->dados_pessoa_id ?: $this->criarPessoa($utilizador)->id;

        return Docente::query()->where('dados_pessoa_id', $pessoaId)->first()
            ?? Docente::create([
                'estabelecimento_id' => Estabelecimento::current()?->id,
                'dados_pessoa_id' => $pessoaId,
                'estado' => (int) $utilizador->estado === 1 ? 1 : 0,
            ]);
    }

    private function criarPessoa(User $utilizador): DadosPessoa
    {
        $pessoa = DadosPessoa::create([
            'nome_completo' => $utilizador->name,
            'email' => $utilizador->email,
            'numero_identificacao' => self::PREFIXO_BI_PROVISORIO.$utilizador->id,
            'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR,
        ]);

        $utilizador->forceFill(['dados_pessoa_id' => $pessoa->id])->save();

        return $pessoa;
    }
}
