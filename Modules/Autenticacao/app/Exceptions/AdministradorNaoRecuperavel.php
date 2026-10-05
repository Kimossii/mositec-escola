<?php

namespace Modules\Autenticacao\Exceptions;

use Modules\Core\Tenancy\Exceptions\RecuperacaoDeAdministradorRecusada;

/**
 * Não foi possível escolher, sem ambiguidade, o administrador a recuperar. É uma recusa de domínio
 * (traz o motivo) para o contrato do Core a poder propagar sem a Plataforma conhecer este módulo.
 */
class AdministradorNaoRecuperavel extends RecuperacaoDeAdministradorRecusada {}
