<?php

namespace Modules\Core\Comunicacao\Email\Dominio;

use RuntimeException;

/** O fornecedor não está disponível (sem chave, erro de rede ou resposta inesperada). A mensagem nunca leva a chave. */
class ClienteDeDominiosIndisponivel extends RuntimeException {}
