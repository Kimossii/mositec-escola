<?php

namespace Modules\Permissao\Support;

use Illuminate\Validation\ValidationException;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;

/**
 * Consulta e validação das acções aplicáveis a cada módulo. A fonte de verdade
 * é Modulo::acoesAplicaveis(); esta classe só a aplica a linhas da BD (ids)
 * e é resolvida pelo container (substituível em testes).
 */
class AcoesAplicaveis
{
    /** @return list<string> */
    public function doModulo(Modulo $modulo): array
    {
        return $modulo->acoesAplicaveis();
    }

    /** Módulo da BD (`modulos.nome` = valor do enum); desconhecido => só a base. */
    public function doRegistro(ModuloRegistro $registro): array
    {
        $modulo = Modulo::tryFrom((int) $registro->nome);

        return $modulo ? $this->doModulo($modulo) : Modulo::ACOES_BASE;
    }

    /** @return list<string> união das acções aplicáveis a pelo menos um módulo */
    public function emUso(): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(fn (Modulo $m) => $this->doModulo($m), Modulo::cases()),
        )));
    }

    public function aplicavel(Modulo $modulo, string $acao): bool
    {
        return in_array($acao, $this->doModulo($modulo), true);
    }

    /**
     * Rejeita apenas NOVAS concessões inaplicáveis. Negações (permitido=false)
     * e pares já gravados nunca são rejeitados: assim um par antigo não impede
     * guardar e o admin consegue limpá-lo.
     *
     * @param  list<array{modulo_id: int, acao_id: int, permitido?: bool}>  $celulas
     * @param  list<string>  $gravados  pares "modulo_id-acao_id" já gravados
     *
     * @throws ValidationException
     */
    public function validarCelulas(array $celulas, array $gravados = []): void
    {
        if ($celulas === []) {
            return;
        }

        $modulos = ModuloRegistro::whereIn('id', array_column($celulas, 'modulo_id'))->get()->keyBy('id');
        $acoes = Acao::whereIn('id', array_column($celulas, 'acao_id'))->pluck('nome', 'id');

        foreach ($celulas as $celula) {
            if (($celula['permitido'] ?? true) === false
                || in_array("{$celula['modulo_id']}-{$celula['acao_id']}", $gravados, true)) {
                continue;
            }

            $registo = $modulos[$celula['modulo_id']] ?? null;
            $acao = $acoes[$celula['acao_id']] ?? null;

            if ($registo === null || $acao === null) {
                continue; // a existência é validada pelos Form Requests
            }

            if (! in_array($acao, $this->doRegistro($registo), true)) {
                $modulo = Modulo::tryFrom((int) $registo->nome)?->label() ?? $registo->descricao;

                throw ValidationException::withMessages([
                    'celulas' => "A acção «{$acao}» não é aplicável ao módulo «{$modulo}».",
                ]);
            }
        }
    }
}
