<?php

namespace Modules\Core\Comunicacao\Email;

/**
 * Todo o email do sistema declara a sua categoria. ESSENCIAL (recuperar senha, convites, criação de acesso,
 * segurança) sai sempre, mesmo que a escola não tenha o serviço de notificações. OPCIONAL (avisos, cobranças,
 * faltas) é o que o serviço comercial de notificações liga ou desliga; esse controlo ainda não existe, mas
 * com a categoria já marcada passa a ser um filtro, sem reescrever os emails.
 */
enum CategoriaEmail: string
{
    case ESSENCIAL = 'essencial';
    case OPCIONAL = 'opcional';
}
