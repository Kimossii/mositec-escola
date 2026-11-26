<?php

namespace Modules\Core\Comunicacao\Email;

use LogicException;
use Modules\Core\Tenancy\Contracts\ContactoDaEscola;
use Modules\Core\Tenancy\Contracts\EnderecoDoTenant;
use Modules\Core\Tenancy\TenantContext;

/**
 * Resolve, para o tenant corrente, quem aparece como remetente e para onde apontam as ligações dos emails.
 * Único ponto onde o From é montado: nenhuma Notification/Mailable o faz à mão.
 *
 * - Nome: o da escola. Endereço: sempre o central da plataforma (`mail.from.address`), porque o domínio de
 *   envio verificado é o da MosiTec, não o da escola.
 * - Reply-To: o email institucional do estabelecimento, se válido (sem ele, a resposta não vai para lado nenhum
 *   em particular). Uma configuração própria (`configuracoes_email`) só se justifica quando houver SMTP/domínio próprio.
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
        return new Remetente(
            nome: $this->contexto->atual()->nome,
            endereco: (string) config('mail.from.address'),
            replyTo: $this->contacto->email(),
        );
    }

    /** URL absoluto no domínio principal do tenant corrente. */
    public function url(string $caminho = '/'): string
    {
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
