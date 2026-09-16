<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class TipoDocumentoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_estado_descricao_e_sincronizada_ao_criar(): void
    {
        $tipo = TipoDocumento::create([
            'nome' => 'Bilhete de Identidade',
            'slug' => 'bi',
        ]);

        $this->assertSame(1, $tipo->estado);
        $this->assertSame('Ativo', $tipo->estado_descricao);
    }

    public function test_estado_descricao_e_sincronizada_ao_desactivar(): void
    {
        $tipo = TipoDocumento::create(['nome' => 'Outro', 'slug' => 'outro']);

        $tipo->update(['estado' => 0]);

        $this->assertSame('Inativo', $tipo->fresh()->estado_descricao);
    }

    public function test_slug_e_unico(): void
    {
        TipoDocumento::create(['nome' => 'Bilhete de Identidade', 'slug' => 'bi']);

        $this->expectException(QueryException::class);

        TipoDocumento::create(['nome' => 'Outro BI', 'slug' => 'bi']);
    }
}
