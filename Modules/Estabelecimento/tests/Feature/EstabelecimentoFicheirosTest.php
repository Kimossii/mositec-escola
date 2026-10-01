<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Estabelecimento\Actions\AtualizarLogotipoEstabelecimentoAction;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class EstabelecimentoFicheirosTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_logotipo_fica_no_disco_public_sob_o_prefixo_do_tenant(): void
    {
        Storage::fake('public');

        $estabelecimento = app(AtualizarLogotipoEstabelecimentoAction::class)
            ->executar(Estabelecimento::current(), UploadedFile::fake()->image('logo.png'));

        $this->assertStringStartsWith("tenants/{$this->tenant->id}/estabelecimento/logotipos/", $estabelecimento->logotipo_path);
        Storage::disk('public')->assertExists($estabelecimento->logotipo_path);
        $this->assertStringContainsString('/storage/tenants/' . $this->tenant->id . '/estabelecimento/logotipos/', $estabelecimento->logotipo_url);
    }

    public function test_o_logotipo_de_b_fica_sob_o_prefixo_de_b(): void
    {
        Storage::fake('public');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $deB = $this->noTenant($outro, fn () => app(AtualizarLogotipoEstabelecimentoAction::class)
            ->executar(Estabelecimento::current(), UploadedFile::fake()->image('logo.png')));
        $deA = app(AtualizarLogotipoEstabelecimentoAction::class)
            ->executar(Estabelecimento::current(), UploadedFile::fake()->image('logo.png'));

        $this->assertStringStartsWith("tenants/{$outro->id}/estabelecimento/logotipos/", $deB->logotipo_path);
        $this->assertStringStartsWith("tenants/{$this->tenant->id}/estabelecimento/logotipos/", $deA->logotipo_path);
    }

    public function test_substituir_o_logotipo_apaga_o_anterior(): void
    {
        Storage::fake('public');
        $action = app(AtualizarLogotipoEstabelecimentoAction::class);

        $primeiro = $action->executar(Estabelecimento::current(), UploadedFile::fake()->image('um.png'))->logotipo_path;
        $segundo = $action->executar(Estabelecimento::current(), UploadedFile::fake()->image('dois.png'))->logotipo_path;

        Storage::disk('public')->assertMissing($primeiro);
        Storage::disk('public')->assertExists($segundo);
    }

    public function test_substituir_o_logotipo_nao_apaga_um_caminho_que_nao_e_do_tenant(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tenants/999/estabelecimento/logotipos/alheio.png', 'x');
        $estabelecimento = Estabelecimento::current();
        $estabelecimento->forceFill(['logotipo_path' => 'tenants/999/estabelecimento/logotipos/alheio.png'])->save();

        app(AtualizarLogotipoEstabelecimentoAction::class)->executar($estabelecimento, UploadedFile::fake()->image('novo.png'));

        Storage::disk('public')->assertExists('tenants/999/estabelecimento/logotipos/alheio.png');
    }
}
