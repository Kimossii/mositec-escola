<?php

namespace Modules\Licenciamento\Assinatura;

use SensitiveParameter;

interface CodecoDeLicenca
{
    /** @param array<string,mixed> $claims */
    public function assinar(array $claims, #[SensitiveParameter] string $chavePrivada, string $kid): string;

    /**
     * @param  array<string,string>  $chavesPublicas  kid => chave pública (32 bytes binários)
     * @return array<string,mixed> claims, só depois de a assinatura (payload + footer) ser válida
     */
    public function verificar(string $token, array $chavesPublicas): array;
}
