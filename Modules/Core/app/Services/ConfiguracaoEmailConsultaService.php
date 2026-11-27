<?php

namespace Modules\Core\Services;

use Modules\Core\Models\ConfiguracaoEmail;

/** Leitura da configuração SMTP da escola corrente para o ecrã. NUNCA devolve a senha: só se existe (`tem_senha`). */
class ConfiguracaoEmailConsultaService
{
    public function actual(): ?ConfiguracaoEmail
    {
        return ConfiguracaoEmail::query()->first();
    }

    /** @return array<string, mixed>|null */
    public function paraEcra(): ?array
    {
        $c = $this->actual();

        if ($c === null) {
            return null;
        }

        return [
            'servidor' => $c->servidor,
            'porta' => $c->porta,
            'encriptacao' => $c->encriptacao->value,
            'utilizador' => $c->utilizador,
            'tem_senha' => $c->senha !== null && $c->senha !== '',
            'validar_certificado' => $c->validar_certificado,
            'dominio_ehlo' => $c->dominio_ehlo,
            'tempo_limite' => $c->tempo_limite,
            'remetente_nome' => $c->remetente_nome,
            'remetente_endereco' => $c->remetente_endereco,
            'responder_para' => $c->responder_para,
            'estado' => $c->estado->value,
            'estado_descricao' => $c->estado_descricao,
            'activo' => $c->activo,
            'bloqueada_pela_plataforma' => $c->bloqueada_pela_plataforma,
            'verificada_em' => $c->verificada_em?->format('Y-m-d H:i:s'),
            'ultimo_erro' => $c->ultimo_erro,
        ];
    }
}
