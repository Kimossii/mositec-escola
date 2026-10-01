<?php

namespace Modules\Autenticacao\Exceptions;

use RuntimeException;

/** Não foi possível escolher, sem ambiguidade, o administrador a recuperar. */
class AdministradorNaoRecuperavel extends RuntimeException {}
