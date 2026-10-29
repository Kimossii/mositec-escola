<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Exportacao\Exportador;
use Modules\Core\Exportacao\GerarExportacaoJob;
use Modules\Core\Models\PedidoExportacao;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ExportacaoEmFilaTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private function como(Perfil $perfil, string $email): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('x')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $u;
    }

    public function test_pdf_com_fila_a_serio_vai_para_fila_e_so_o_dono_descarrega(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->cenarioDeDocencia();
        $this->criarDocente();
        config(['queue.default' => 'database', 'exportacao.linhas_imediatas' => ['pdf' => 0, 'zip' => 0, 'csv' => 0, 'excel' => 0]]);
        $dono = $this->como(Perfil::ADMIN_ESCOLA, 'dono@example.com');

        $resposta = $this->actingAs($dono)->getJson('/docentes/exportar?formato=pdf')->assertStatus(202);
        $pedido = PedidoExportacao::findOrFail($resposta->json('pedido_id'));
        Queue::assertPushed(GerarExportacaoJob::class);
        $this->assertSame(PedidoExportacao::PENDENTE, $pedido->estado);

        (new GerarExportacaoJob($pedido->id))->handle(app(Exportador::class));

        $this->assertSame(PedidoExportacao::PRONTO, $pedido->fresh()->estado);
        Storage::disk('local')->assertExists($pedido->fresh()->caminho);
        Storage::disk('local')->assertMissing(GerarExportacaoJob::origem($pedido->id));
        $this->get("/ficheiros/{$pedido->id}/descarregar")->assertOk();

        $outro = $this->como(Perfil::ADMIN_ESCOLA, 'outro@example.com');
        $this->actingAs($outro)->get("/ficheiros/{$pedido->id}/descarregar")->assertNotFound();
    }

    public function test_pedido_igual_a_preparar_e_reaproveitado_e_ha_limite_de_pendentes(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->cenarioDeDocencia();
        $this->criarDocente();
        config(['queue.default' => 'database', 'exportacao.linhas_imediatas' => ['pdf' => 0, 'zip' => 0, 'csv' => 0, 'excel' => 0]]);
        $this->actingAs($this->como(Perfil::ADMIN_ESCOLA, 'dono@example.com'));

        $a = $this->getJson('/docentes/exportar?formato=pdf')->assertStatus(202)->json('pedido_id');
        $b = $this->getJson('/docentes/exportar?formato=pdf')->assertStatus(202)->json('pedido_id');
        $this->assertSame($a, $b);

        $this->getJson('/docentes/exportar?formato=csv')->assertStatus(202);
        $this->getJson('/docentes/exportar?formato=zip')->assertStatus(202);
        $this->getJson('/docentes/exportar?formato=excel')->assertStatus(429);
        $this->assertSame(3, PedidoExportacao::count());
    }

    public function test_limpar_marca_presos_como_falhados_e_apaga_o_que_expirou(): void
    {
        Storage::fake('local');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->cenarioDeDocencia();
        $dono = $this->como(Perfil::ADMIN_ESCOLA, 'dono@example.com');
        $this->actingAs($dono);
        $base = ['user_id' => $dono->id, 'titulo' => 'X', 'formato' => 'pdf', 'nome' => 'x.pdf', 'linhas' => 1];
        $preso = PedidoExportacao::create($base + ['estado' => PedidoExportacao::PENDENTE]);
        $preso->forceFill(['created_at' => now()->subHour()])->save();
        Storage::disk('local')->put(GerarExportacaoJob::origem($preso->id), '{}');

        $this->artisan('exportacoes:limpar', ['--todos' => true])->assertSuccessful();

        $this->assertSame(PedidoExportacao::ERRO, $preso->fresh()->estado);
        Storage::disk('local')->assertMissing(GerarExportacaoJob::origem($preso->id));
    }

    public function test_em_sync_continua_a_descarga_imediata(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->cenarioDeDocencia();
        config(['queue.default' => 'sync']);

        $this->actingAs($this->como(Perfil::ADMIN_ESCOLA, 'dono@example.com'))->get('/docentes/exportar?formato=csv')->assertOk()->assertDownload();
        $this->assertSame(0, PedidoExportacao::count());
    }

    public function test_eliminar_apaga_o_ficheiro_so_do_dono_e_nunca_os_a_preparar(): void
    {
        Storage::fake('local');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->cenarioDeDocencia();
        $dono = $this->como(Perfil::ADMIN_ESCOLA, 'dono@example.com');
        $outro = $this->como(Perfil::ADMIN_ESCOLA, 'outro@example.com');
        $base = ['titulo' => 'Docentes', 'formato' => 'pdf', 'nome' => 'd.pdf', 'linhas' => 1];
        $pronto = PedidoExportacao::create($base + ['user_id' => $dono->id, 'estado' => PedidoExportacao::PRONTO, 'caminho' => 'exportacoes/x/1.pdf']);
        $preparar = PedidoExportacao::create($base + ['user_id' => $dono->id, 'estado' => PedidoExportacao::PENDENTE]);
        Storage::disk('local')->put('exportacoes/x/1.pdf', 'x');

        $this->actingAs($outro)->delete('/ficheiros', ['ids' => [$pronto->id]]);
        $this->assertNotNull(PedidoExportacao::find($pronto->id));

        $this->actingAs($dono)->delete('/ficheiros', ['ids' => [$pronto->id, $preparar->id]])->assertRedirect();
        $this->assertNull(PedidoExportacao::find($pronto->id));
        Storage::disk('local')->assertMissing('exportacoes/x/1.pdf');
        $this->assertNotNull(PedidoExportacao::find($preparar->id));
    }

    public function test_descarregar_pasta_devolve_um_zip_so_com_os_ficheiros_prontos_do_dono(): void
    {
        Storage::fake('local');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->cenarioDeDocencia();
        $dono = $this->como(Perfil::ADMIN_ESCOLA, 'dono@example.com');
        $base = ['titulo' => 'Docentes', 'formato' => 'pdf', 'linhas' => 1, 'user_id' => $dono->id, 'estado' => PedidoExportacao::PRONTO];
        PedidoExportacao::create($base + ['nome' => 'a.pdf', 'caminho' => 'exportacoes/x/1.pdf']);
        PedidoExportacao::create($base + ['nome' => 'a.pdf', 'caminho' => 'exportacoes/x/2.pdf']);
        Storage::disk('local')->put('exportacoes/x/1.pdf', 'um');
        Storage::disk('local')->put('exportacoes/x/2.pdf', 'dois');

        $resposta = $this->actingAs($dono)->get('/ficheiros/pasta/descarregar?titulo=Docentes')->assertOk();
        $zip = new \ZipArchive;
        $zip->open($resposta->baseResponse->getFile()->getPathname());
        $this->assertSame(2, $zip->numFiles);

        $outro = $this->como(Perfil::ADMIN_ESCOLA, 'outro@example.com');
        $this->actingAs($outro)->get('/ficheiros/pasta/descarregar?titulo=Docentes')->assertNotFound();
    }

    public function test_partilha_so_com_quem_tem_a_permissao_e_a_descarga_respeita_a_revogacao(): void
    {
        Storage::fake('local');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->cenarioDeDocencia();
        $dono = $this->como(Perfil::ADMIN_ESCOLA, 'dono@example.com');
        $colega = $this->como(Perfil::ADMIN_ESCOLA, 'colega@example.com');
        $semPermissao = $this->como(Perfil::FUNCIONARIO, 'func@example.com');
        $pedido = PedidoExportacao::create(['user_id' => $dono->id, 'titulo' => 'Docentes', 'formato' => 'pdf', 'nome' => 'd.pdf', 'permissao' => 'docente.exportar', 'linhas' => 1, 'estado' => PedidoExportacao::PRONTO, 'caminho' => 'exportacoes/x/9.pdf']);
        Storage::disk('local')->put('exportacoes/x/9.pdf', 'pdf');

        // Só aparecem como destinatários os colegas com a permissão.
        $ids = collect($this->actingAs($dono)->getJson("/ficheiros/{$pedido->id}/destinatarios")->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($colega->id));
        $this->assertFalse($ids->contains($semPermissao->id));

        // Sem permissão: recusado. Sem partilha: 404 ao descarregar.
        $this->post('/ficheiros/partilhar', ['ids' => [$pedido->id], 'destinatarios' => [$semPermissao->id]])->assertSessionHasErrors('destinatarios');
        $this->actingAs($colega)->get("/ficheiros/{$pedido->id}/descarregar")->assertNotFound();

        // Partilhado: o colega vê e descarrega; não elimina.
        $this->actingAs($dono)->post('/ficheiros/partilhar', ['ids' => [$pedido->id], 'destinatarios' => [$colega->id]])->assertRedirect();
        $this->actingAs($colega)->get("/ficheiros/{$pedido->id}/descarregar")->assertOk();
        $this->get('/ficheiros')->assertInertia(fn ($p) => $p->has('partilhados', 1)->where('partilhados.0.partilhado_por', 'dono@example.com'));
        $this->delete('/ficheiros', ['ids' => [$pedido->id]]);
        $this->assertNotNull(PedidoExportacao::find($pedido->id));

        // Revogado (lista vazia): deixa de poder descarregar.
        $this->actingAs($dono)->post('/ficheiros/partilhar', ['ids' => [$pedido->id], 'destinatarios' => []])->assertRedirect();
        $this->actingAs($colega)->get("/ficheiros/{$pedido->id}/descarregar")->assertNotFound();
    }
}
