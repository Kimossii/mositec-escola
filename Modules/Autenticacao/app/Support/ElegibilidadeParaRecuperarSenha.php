<?php

namespace Modules\Autenticacao\Support;

use Modules\Core\Enums\Estado;
use UnhandledMatchError;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;

/**
 * Quem pode recuperar a senha por email. Regra deliberadamente apertada (recuperar uma conta por email é tomar conta dela se
 * o email for comprometido): conta activa, com email de login CONFIRMADO, e cujos perfis são todos da lista configurada
 * (`comunicacao.recuperacao_senha.perfis`), sem permissões atribuídas à parte. Quem não cumpre fala com o administrador.
 */
class ElegibilidadeParaRecuperarSenha
{
    /** @return list<int> valores de Perfil permitidos (um slug desconhecido na configuração é ignorado). */
    private function perfisPermitidos(): array
    {
        $valores = [];

        foreach ((array) config('comunicacao.recuperacao_senha.perfis') as $slug) {
            try {
                $valores[] = Perfil::fromSlug((string) $slug)->value;
            } catch (UnhandledMatchError) {
                // Slug desconhecido na configuração: ignorado, nunca alarga a lista.
            }
        }

        return $valores;
    }

    public function haPerfisElegiveis(): bool
    {
        return $this->perfisPermitidos() !== [];
    }

    public function permite(User $utilizador): bool
    {
        $permitidos = $this->perfisPermitidos();

        if ($permitidos === []
            || (int) $utilizador->estado !== Estado::ATIVO->value
            || ($utilizador->tipo_login ?? TipoLogin::EMAIL) !== TipoLogin::EMAIL
            || trim((string) $utilizador->email) === ''
            || $utilizador->email_verified_at === null) {
            return false;
        }

        // Consulta a BD (não a relação carregada), como as outras regras de perfil fixo.
        $perfis = $utilizador->roles()->pluck('nome')->map(fn ($nome) => (int) $nome);

        return $perfis->isNotEmpty()
            && $perfis->every(fn (int $perfil) => in_array($perfil, $permitidos, true))
            && ! $utilizador->permissoes()->exists();
    }
}
