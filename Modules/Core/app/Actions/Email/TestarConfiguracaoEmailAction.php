<?php

namespace Modules\Core\Actions\Email;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Comunicacao\Email\MailerDaEscola;
use Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Models\ConfiguracaoEmail;
use Throwable;

/**
 * Envia um email de teste, de forma síncrona, pelo servidor configurado. Só um envio bem-sucedido verifica e activa a
 * configuração; uma falha marca-a «com falha» com um erro curto, sem a senha nem o utilizador. O servidor é revalidado
 * (SSRF) no momento do envio, não só ao guardar.
 */
class TestarConfiguracaoEmailAction
{
    /** @param  ?Closure(array, ConfiguracaoEmail, string): void  $enviar  injectável nos testes; por omissão envia a sério */
    public function __construct(private ?Closure $enviar = null) {}

    public function executar(ConfiguracaoEmail $c, string $destino): ConfiguracaoEmail
    {
        if ($c->bloqueada_pela_plataforma) {
            throw ValidationException::withMessages(['geral' => 'A Plataforma bloqueou o servidor próprio desta escola. Contacte a MosiTec.']);
        }

        $antes = ['estado' => $c->estado?->value, 'activo' => (bool) $c->activo];
        $impressao = $c->impressaoDaLigacao();

        [$erro, $ip] = app(ValidadorDeServidorSmtp::class)->validar($c->servidor, (int) $c->porta);
        $c->ipFixado = $ip;

        if ($erro === null) {
            try {
                ($this->enviar ?? $this->envioReal(...))(MailerDaEscola::configDe($c), $c, $destino);
            } catch (Throwable $e) {
                $erro = $this->resumir($e, $c);
            }
        }

        // O envio pode demorar até ao tempo limite: grava-se sobre a configuração ACTUAL, bloqueada para escrita, e só se
        // ainda for exactamente a que foi testada e não tiver sido bloqueada entretanto.
        $c = DB::transaction(function () use ($c, $impressao, $erro) {
            $actual = ConfiguracaoEmail::query()->lockForUpdate()->find($c->id);

            if ($actual === null || $actual->impressaoDaLigacao() !== $impressao || $actual->bloqueada_pela_plataforma) {
                throw ValidationException::withMessages(['geral' => 'A configuração mudou durante o teste. Teste outra vez.']);
            }

            $actual->forceFill($erro === null
                ? ['estado' => EstadoConfiguracaoEmail::VERIFICADO, 'activo' => true, 'verificada_em' => now(), 'ultimo_erro' => null]
                : ['estado' => EstadoConfiguracaoEmail::COM_FALHA, 'activo' => false, 'ultimo_erro' => $erro])->save();

            return $actual;
        });

        app(RegistarAuditoriaTenantAction::class)->executar(new AuditoriaTenantDTO(
            'configuracao_email.testada',
            'configuracao_email',
            $c->id,
            $antes + ['resultado' => null],
            ['estado' => $c->estado->value, 'activo' => (bool) $c->activo, 'resultado' => $erro === null ? 'sucesso' : 'falha'],
        ));

        return $c;
    }

    private function envioReal(array $config, ConfiguracaoEmail $c, string $destino): void
    {
        Mail::build($config)->raw(
            'Este é um email de teste enviado pela MosiTec para confirmar que a configuração de email da escola está correcta.',
            function ($mensagem) use ($c, $destino) {
                $mensagem->from($c->remetente_endereco, $c->remetente_nome)->to($destino)->subject('Teste de configuração de email');
            },
        );
    }

    private function resumir(Throwable $e, ConfiguracaoEmail $c): string
    {
        $mensagem = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';
        $segredos = array_filter([$c->senha, $c->utilizador], fn ($s) => is_string($s) && $s !== '');
        $mensagem = str_replace($segredos, '***', $mensagem);
        $mensagem = preg_replace('#://[^/\s@]+@#', '://***@', $mensagem) ?? $mensagem;

        return Str::limit(class_basename($e).': '.$mensagem, 199, '…');
    }
}
