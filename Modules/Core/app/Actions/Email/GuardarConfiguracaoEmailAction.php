<?php

namespace Modules\Core\Actions\Email;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\Comunicacao\Email\EncriptacaoSmtp;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Models\ConfiguracaoEmail;

/**
 * Cria ou actualiza a configuração SMTP da escola corrente. Qualquer alteração a um campo de ligação ou do remetente
 * invalida a verificação anterior: volta a «por verificar» e fica inactiva até um novo teste de envio bem-sucedido.
 * Senha ausente ou vazia mantém a actual. A auditoria nunca leva a senha, só `senha_alterada`.
 */
class GuardarConfiguracaoEmailAction
{
    private const CAMPOS = [
        'servidor', 'porta', 'encriptacao', 'utilizador', 'validar_certificado', 'dominio_ehlo',
        'tempo_limite', 'remetente_nome', 'remetente_endereco', 'responder_para',
    ];

    public function __construct(private ValidadorDeServidorSmtp $validador, private RegistarAuditoriaTenantAction $auditoria) {}

    /** @param array<string, mixed> $dados */
    public function executar(array $dados): ConfiguracaoEmail
    {
        $this->validarServidor($dados);

        return DB::transaction(function () use ($dados) {
            $existente = ConfiguracaoEmail::query()->lockForUpdate()->first();
            $novos = $this->normalizar($dados);
            $senha = isset($dados['senha']) && $dados['senha'] !== '' ? (string) $dados['senha'] : null;
            $senhaAlterada = $senha !== null && ($existente === null || $senha !== $existente->senha);

            $antes = $existente === null ? [] : $this->visivel($existente) + ['senha_alterada' => false];

            $configuracao = $existente ?? new ConfiguracaoEmail;
            $mudou = $existente === null || $senhaAlterada || $this->mudou($existente, $novos);

            $configuracao->fill($novos);

            if ($senhaAlterada) {
                $configuracao->senha = $senha;
            }

            if ($mudou) {
                $configuracao->estado = EstadoConfiguracaoEmail::POR_VERIFICAR;
                $configuracao->activo = false;
                $configuracao->ultimo_erro = null;
                $configuracao->verificada_em = null;
            }

            $configuracao->save();

            $this->auditoria->executar(new AuditoriaTenantDTO(
                'configuracao_email.guardada',
                'configuracao_email',
                $configuracao->id,
                $antes,
                $this->visivel($configuracao) + ['senha_alterada' => $senhaAlterada],
            ));

            return $configuracao;
        });
    }

    private function validarServidor(array $dados): void
    {
        $porta = (int) ($dados['porta'] ?? 0);

        if (! in_array($porta, ValidadorDeServidorSmtp::PORTAS, true)) {
            throw ValidationException::withMessages(['porta' => 'A porta tem de ser uma de: '.implode(', ', ValidadorDeServidorSmtp::PORTAS).'.']);
        }

        if (($erro = $this->validador->erro((string) ($dados['servidor'] ?? ''), $porta)) !== null) {
            throw ValidationException::withMessages(['servidor' => $erro]);
        }
    }

    /** @return array<string, mixed> */
    private function normalizar(array $dados): array
    {
        $texto = fn (string $campo) => isset($dados[$campo]) && trim((string) $dados[$campo]) !== '' ? trim((string) $dados[$campo]) : null;

        return [
            'servidor' => trim((string) $dados['servidor']),
            'porta' => (int) $dados['porta'],
            'encriptacao' => $dados['encriptacao'] instanceof EncriptacaoSmtp ? $dados['encriptacao'] : EncriptacaoSmtp::from((int) $dados['encriptacao']),
            'utilizador' => $texto('utilizador'),
            'validar_certificado' => (bool) ($dados['validar_certificado'] ?? true),
            'dominio_ehlo' => $texto('dominio_ehlo'),
            'tempo_limite' => (int) ($dados['tempo_limite'] ?? 15),
            'remetente_nome' => $texto('remetente_nome'),
            'remetente_endereco' => trim((string) $dados['remetente_endereco']),
            'responder_para' => $texto('responder_para'),
        ];
    }

    private function mudou(ConfiguracaoEmail $existente, array $novos): bool
    {
        foreach (self::CAMPOS as $campo) {
            if ($existente->{$campo} !== $novos[$campo]) {
                return true;
            }
        }

        return false;
    }

    /** O estado da configuração para a auditoria: escalares, nunca a senha. */
    private function visivel(ConfiguracaoEmail $c): array
    {
        $dados = [];

        foreach (self::CAMPOS as $campo) {
            $valor = $c->{$campo};
            $dados[$campo] = $valor instanceof EncriptacaoSmtp ? $valor->value : $valor;
        }

        return $dados + ['estado' => $c->estado?->value, 'activo' => (bool) $c->activo];
    }
}
