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

        $this->artisan('exportacoes:limpar')->assertSuccessful();

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
}
