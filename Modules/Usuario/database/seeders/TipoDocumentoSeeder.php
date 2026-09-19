<?php

namespace Modules\Usuario\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Usuario\Models\TipoDocumento;

class TipoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['nome' => 'Bilhete de Identidade', 'slug' => 'bi'],
            ['nome' => 'Passaporte', 'slug' => 'passaporte'],
            ['nome' => 'Certidão de Nascimento', 'slug' => 'certidao_nascimento'],
            ['nome' => 'Certificado', 'slug' => 'certificado'],
            ['nome' => 'Declaração', 'slug' => 'declaracao'],
            ['nome' => 'Outro', 'slug' => 'outro'],
        ];

        foreach ($tipos as $tipo) {
            TipoDocumento::updateOrCreate(
                ['slug' => $tipo['slug']],
                ['nome' => $tipo['nome']],
            );
        }
    }
}
