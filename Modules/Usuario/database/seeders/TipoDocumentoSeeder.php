<?php

namespace Modules\Usuario\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Usuario\Actions\CriarTiposDocumentoPadraoAction;

class TipoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        app(CriarTiposDocumentoPadraoAction::class)->executar();
    }
}
