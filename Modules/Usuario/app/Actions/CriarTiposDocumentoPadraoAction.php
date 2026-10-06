<?php

namespace Modules\Usuario\Actions;

use Modules\Usuario\Models\TipoDocumento;

/**
 * Tipos de documento por omissão do tenant corrente. Idempotente.
 * Partilhada pelo TipoDocumentoSeeder e pelo provisionador de tenants.
 */
class CriarTiposDocumentoPadraoAction
{
    private const TIPOS = [
        ['nome' => 'Bilhete de Identidade', 'slug' => 'bi'],
        ['nome' => 'Passaporte', 'slug' => 'passaporte'],
        ['nome' => 'Certidão de Nascimento', 'slug' => 'certidao_nascimento'],
        ['nome' => 'Certificado', 'slug' => 'certificado'],
        ['nome' => 'Declaração', 'slug' => 'declaracao'],
        ['nome' => 'Outro', 'slug' => 'outro'],
    ];

    public function executar(): void
    {
        foreach (self::TIPOS as $tipo) {
            TipoDocumento::updateOrCreate(
                ['slug' => $tipo['slug']],
                ['nome' => $tipo['nome']],
            );
        }
    }
}
