<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Http\Requests\GuardarDocumentoPessoaRequest;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class GuardarDocumentoPessoaRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_falha_sem_tipo_documento_e_sem_ficheiro(): void
    {
        $validator = Validator::make([], (new GuardarDocumentoPessoaRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tipo_documento_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('ficheiro', $validator->errors()->toArray());
    }

    public function test_falha_com_tipo_documento_inexistente(): void
    {
        $validator = Validator::make([
            'tipo_documento_id' => 999,
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ], (new GuardarDocumentoPessoaRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tipo_documento_id', $validator->errors()->toArray());
    }

    public function test_falha_quando_data_validade_e_anterior_a_data_emissao(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $validator = Validator::make([
            'tipo_documento_id' => $tipo->id,
            'data_emissao' => '2025-01-10',
            'data_validade' => '2024-01-10',
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ], (new GuardarDocumentoPessoaRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('data_validade', $validator->errors()->toArray());
    }

    public function test_passa_com_dados_validos(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $validator = Validator::make([
            'tipo_documento_id' => $tipo->id,
            'numero_documento' => '0012345LA042',
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ], (new GuardarDocumentoPessoaRequest())->rules());

        $this->assertFalse($validator->fails());
    }
}
