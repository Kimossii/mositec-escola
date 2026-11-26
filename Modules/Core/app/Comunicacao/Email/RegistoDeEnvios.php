<?php

namespace Modules\Core\Comunicacao\Email;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Core\Models\EnvioEmail;
use Modules\Core\Tenancy\TenantContext;
use Throwable;

/**
 * Regista cada tentativa de envio do tenant corrente (histórico, falhas visíveis, base do consumo). NUNCA lança nem atrapalha o
 * envio: se não houver tenant ou a escrita falhar, só deixa um aviso no log. Guarda o destinatário mascarado e um erro curto,
 * sem segredos.
 */
class RegistoDeEnvios
{
    public function __construct(private TenantContext $contexto) {}

    public function enviado(NotificacaoDeTenant $notificacao, object $destinatario): void
    {
        $this->registar($notificacao, $destinatario, EstadoEnvioEmail::ENVIADO);
    }

    public function falhou(NotificacaoDeTenant $notificacao, object $destinatario, Throwable $erro): void
    {
        $this->registar($notificacao, $destinatario, EstadoEnvioEmail::FALHOU, self::resumirErro($erro));
    }

    public function descartado(NotificacaoDeTenant $notificacao, object $destinatario, EstadoEnvioEmail $motivo): void
    {
        $this->registar($notificacao, $destinatario, $motivo);
    }

    private function registar(NotificacaoDeTenant $notificacao, object $destinatario, EstadoEnvioEmail $estado, ?string $erro = null): void
    {
        if (! $this->contexto->temTenant()) {
            return;
        }

        try {
            EnvioEmail::create([
                'notificacao' => class_basename($notificacao),
                'categoria' => $notificacao->categoria(),
                'estado' => $estado,
                // Hoje todos os envios saem pela infra central; com infra própria por escola este valor passa a vir do canal usado.
                'canal' => CanalEnvioEmail::CENTRAL,
                'destinatario_user_id' => $this->utilizadorDe($destinatario),
                'destinatario_mascarado' => self::mascarar($this->enderecoDe($destinatario)),
                'erro' => $erro,
            ]);
        } catch (Throwable $e) {
            Log::warning('Não foi possível registar o envio de email.', ['causa' => class_basename($e)]);
        }
    }

    private function utilizadorDe(object $destinatario): ?int
    {
        return $destinatario instanceof Model && $destinatario->getTable() === 'users' ? (int) $destinatario->getKey() : null;
    }

    private function enderecoDe(object $destinatario): ?string
    {
        if ($destinatario instanceof AnonymousNotifiable) {
            $rota = $destinatario->routes['mail'] ?? null;

            return is_array($rota) ? (string) array_key_first($rota) : (is_string($rota) ? $rota : null);
        }

        $rota = method_exists($destinatario, 'routeNotificationFor') ? $destinatario->routeNotificationFor('mail') : null;

        return is_string($rota) ? $rota : null;
    }

    /** `pai@gmail.com` → `p***@gmail.com`. */
    public static function mascarar(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $dominio] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$dominio;
    }

    /** Classe e mensagem curta, sem endereços de email nem credenciais embutidas em URLs. */
    public static function resumirErro(Throwable $erro): string
    {
        $mensagem = preg_replace('/\s+/', ' ', $erro->getMessage()) ?? '';
        $mensagem = preg_replace('#://[^/\s@]+@#', '://***@', $mensagem) ?? $mensagem;
        $mensagem = preg_replace('/[\w.+-]+@[\w-]+(\.[\w-]+)+/', '***@***', $mensagem) ?? $mensagem;

        return Str::limit(class_basename($erro).': '.$mensagem, 480, '…');
    }
}
