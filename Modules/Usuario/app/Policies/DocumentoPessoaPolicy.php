<?php

namespace Modules\Usuario\Policies;

use Modules\Core\Contracts\VerificaProfessorLeccionaAluno;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;

/**
 * Âmbito de leitura dos documentos pessoais (acresce à permissão `documento-pessoa.ver`):
 * a própria pessoa; gestão (qualquer perfil activo que não seja professor/aluno/encarregado) vê tudo;
 * aluno só os seus; professor só alunos com matrícula activa nas turmas onde tem atribuição em vigor.
 */
class DocumentoPessoaPolicy
{
    public function verDocumentos(User $utilizador, DadosPessoa $alvo): bool
    {
        if ($utilizador->dados_pessoa_id !== null && (int) $utilizador->dados_pessoa_id === (int) $alvo->id) {
            return true;
        }

        if ($this->temAmbitoLargo($utilizador)) {
            return true;
        }

        if ($utilizador->ePerfilAluno() || $utilizador->dados_pessoa_id === null) {
            return false;
        }

        return $this->eAlunoDasTurmasDoProfessor($utilizador, $alvo);
    }

    /** Âmbito largo: um perfil activo, que não seja de professor/aluno/encarregado, que ele próprio dê `documento-pessoa.ver`. */
    private function temAmbitoLargo(User $utilizador): bool
    {
        $perfis = $utilizador->roles()
            ->where('estado', Estado::ATIVO->value)
            ->whereNotIn('nome', [Perfil::PROFESSOR->value, Perfil::ALUNO->value, Perfil::ENCARREGADO->value])
            ->pluck('roles.id');

        return $perfis->isNotEmpty() && RolePermissao::query()
            ->whereIn('role_id', $perfis)
            ->whereHas('modulo', fn ($q) => $q->where('nome', Modulo::DOCUMENTO_PESSOA->value))
            ->whereHas('acao', fn ($q) => $q->where('nome', 'ver'))
            ->exists();
    }

    private function eAlunoDasTurmasDoProfessor(User $utilizador, DadosPessoa $alvo): bool
    {
        // Sem o módulo Docente activo não há âmbito de professor: recusa (403) em vez de 500.
        if (! app()->bound(VerificaProfessorLeccionaAluno::class)) {
            return false;
        }

        return app(VerificaProfessorLeccionaAluno::class)->lecciona((int) $utilizador->dados_pessoa_id, (int) $alvo->id);
    }
}
