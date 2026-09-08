<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabela_salas_tem_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('salas'));
        $this->assertTrue(Schema::hasColumns('salas', [
            'id',
            'estabelecimento_id',
            'codigo',
            'nome',
            'tipo',
            'tipo_descricao',
            'capacidade',
            'localizacao',
            'observacoes',
            'estado',
            'estado_descricao',
            'criado_por',
            'editado_por',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }
}
