<?php

namespace Modules\Plataforma\Actions;

use Modules\Plataforma\Exceptions\DetalheDeAuditoriaInvalido;
use Modules\Plataforma\Models\RegistoDeAuditoria;
use Modules\Plataforma\Models\SuperAdmin;

/**
 * Regista uma acção da Plataforma. O `detalhe` nunca leva segredos: recusa, em qualquer nível,
 * as chaves proibidas (sem distinguir maiúsculas), para a senha ou um token nunca ir parar à tabela.
 */
class RegistarAuditoriaAction
{
    /**
     * Comparação por chave EXACTA (nunca substring): `tokens_revogados` é uma contagem legítima.
     *
     * @var string[]
     */
    private const CHAVES_PROIBIDAS = [
        'senha',
        'senha_temporaria',
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'remember_token',
        'credencial',
        'secret',
    ];

    /**
     * @param  array<string, mixed>  $detalhe
     *
     * @throws DetalheDeAuditoriaInvalido
     */
    public function executar(?SuperAdmin $autor, string $accao, ?string $codigoTenant, array $detalhe = []): void
    {
        if (trim($accao) === '') {
            throw new DetalheDeAuditoriaInvalido('A acção de auditoria é obrigatória.');
        }

        $this->recusarSegredos($detalhe);

        RegistoDeAuditoria::create([
            'super_admin_id' => $autor?->id,
            'accao' => $accao,
            'codigo_tenant' => $codigoTenant,
            'detalhe' => $detalhe === [] ? null : $detalhe,
            // Nulo fora de um pedido HTTP (comandos): não há IP de cliente.
            'ip' => request()->ip(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $dados
     */
    private function recusarSegredos(array $dados): void
    {
        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && in_array(mb_strtolower($chave), self::CHAVES_PROIBIDAS, true)) {
                throw new DetalheDeAuditoriaInvalido("O detalhe da auditoria não pode conter a chave '{$chave}'.");
            }

            if (is_array($valor)) {
                $this->recusarSegredos($valor);
            }
        }
    }
}
