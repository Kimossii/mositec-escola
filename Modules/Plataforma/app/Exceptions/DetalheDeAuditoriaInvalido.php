<?php

namespace Modules\Plataforma\Exceptions;

use InvalidArgumentException;

/**
 * Registo de auditoria recusado (acção vazia ou detalhe com chave de segredo). A mensagem
 * nomeia só a chave, nunca o valor.
 */
class DetalheDeAuditoriaInvalido extends InvalidArgumentException {}
