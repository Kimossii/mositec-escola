<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class TipoDocumentoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_semeia_os_seis_tipos_iniciais(): void
    {
        $this->seed(TipoDocumentoSeeder::class);

        $this->assertSame(6, TipoDocumento::count());
        foreach (['bi', 'passaporte', 'certidao_nascimento', 'certificado', 'declaracao', 'outro'] as $slug) {
            $this->assertTrue(TipoDocumento::where('slug', $slug)->exists(), "slug '{$slug}' não foi semeado");
        }
    }

    public function test_e_idempotente(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $this->seed(TipoDocumentoSeeder::class);

        $this->assertSame(6, TipoDocumento::count());
    }
}
