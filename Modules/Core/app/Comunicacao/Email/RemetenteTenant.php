<?php

namespace Modules\Core\Comunicacao\Email;

use LogicException;
use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Core\Models\DominioEmail;
use Modules\Core\Tenancy\Contracts\ContactoDaEscola;
use Modules\Core\Tenancy\Contracts\EnderecoDoTenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Resolve, para o tenant corrente, quem aparece como remetente e para onde apontam as ligações dos emails.
 * Único ponto onde o From é montado: nenhuma Notification/Mailable o faz à mão.
 *
 * - Nome: o da escola. Endereço: o central da plataforma (`mail.from.address`), porque o domínio de
 *   envio verificado é o da MosiTec, não o da escola. Excepção: com infra própria verificada e activa
 *   (MailerDaEscola) o remetente é o configurado pela escola (nome, endereço e «responder para»).
 * - Reply-To: o email institucional do estabelecimento, se válido (sem ele, a resposta não vai para lado nenhum
 *   em particular).
 * - Ligações: domínio principal do tenant, seja subdomínio da plataforma ou domínio próprio.
 *
 * Precisa de contexto de tenant (num job é dado por ComTenant).
 */
class RemetenteTenant
{
    public function __construct(
        private TenantContext $contexto,
        private EnderecoDoTenant $enderecos,
        private ContactoDaEscola $contacto,
    ) {}

    public function resolver(): Remetente
    {
        return $this->resolverPara(app(MailerDaEscola::class)->configuracao());
    }

    /** O remetente no domínio verificado da escola. */
    public function resolverDeDominio(DominioEmail $dominio): Remetente
    {
        return new Remetente(
            nome: $dominio->remetente_nome ?: $this->contexto->atual()->nome,
            endereco: $dominio->endereco(),
            replyTo: $this->contacto->email(),
        );
    }

    /** O remetente para a configuração própria dada (a mesma que escolheu o mailer); null → remetente central. */
    public function resolverPara(?ConfiguracaoEmail $propria): Remetente
    {
        if ($propria !== null) {
            return new Remetente(
                nome: $propria->remetente_nome ?: $this->contexto->atual()->nome,
                endereco: $propria->remetente_endereco,
                replyTo: $propria->responder_para ?: $this->contacto->email(),
            );
        }

        return new Remetente(
            nome: $this->contexto->atual()->nome,
            endereco: (string) config('mail.from.address'),
            replyTo: $this->contacto->email(),
        );
    }

    /**
     * Fecho dos emails: o nome da escola e os contactos que ela tiver (telefone e email), para quem recebe saber a quem escrever
     * mesmo quando o Reply-To não existe. Sem nenhum contacto, só o nome.
     */
    public function assinatura(): string
    {
        $assinatura = 'Cumprimentos,'."\n\n".$this->contexto->atual()->nome;

        $contactos = array_filter([
            $this->contacto->telefone() !== null ? 'Telefone: '.$this->contacto->telefone() : null,
            $this->contacto->email() !== null ? 'Email: '.$this->contacto->email() : null,
        ]);

        return $contactos === [] ? $assinatura : $assinatura."\n\nPara contactar a escola: ".implode(' · ', $contactos);
    }

    /**
     * URL absoluto para o tenant corrente. Numa instalação única (TENANCY_MODO=unico) a escola é acedida pelo endereço do
     * APP_URL, com a sua porta, por isso é esse o endereço dos links. No modo normal usa o domínio principal do tenant.
     */
    public function url(string $caminho = '/'): string
    {
        if (config('tenancy.modo') === 'unico' && filter_var(config('app.url'), FILTER_VALIDATE_URL)) {
            return rtrim((string) config('app.url'), '/').'/'.ltrim($caminho, '/');
        }

        $tenant = $this->contexto->atual();
        $dominio = $this->enderecos->dominioPrincipal($tenant->id)
            ?? throw new LogicException("O tenant {$tenant->codigo} não tem domínio principal; não é possível gerar ligações para o email.");

        return $this->esquema().'://'.$dominio.'/'.ltrim($caminho, '/');
    }

    private function esquema(): string
    {
        if (config('seguranca.forcar_https')) {
            return 'https';
        }

        return parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
    }
}
