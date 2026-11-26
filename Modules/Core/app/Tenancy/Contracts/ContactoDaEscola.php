<?php

namespace Modules\Core\Tenancy\Contracts;

/**
 * Contacto institucional da escola corrente, para quem precisa dele sem importar o módulo Estabelecimento
 * (ex.: o Reply-To dos emails). Precisa de contexto de tenant.
 */
interface ContactoDaEscola
{
    /** Email institucional preenchido pela escola, ou null se não houver ou não for um endereço válido. */
    public function email(): ?string;

    /** Telefone principal da escola, ou null se não houver. */
    public function telefone(): ?string;
}
