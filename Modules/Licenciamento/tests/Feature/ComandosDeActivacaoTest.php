<?php

namespace Modules\Licenciamento\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Licenciamento\Actions\CriarPlanoAction;
use Modules\Licenciamento\Actions\EmitirLicencaAction;
use Modules\Licenciamento\Actions\SuspenderLicencaAction;
use Modules\Licenciamento\DTOs\EmitirLicencaDTO;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Models\DireitosLocais;
use Modules\Licenciamento\Models\EventoDeLicenca;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Models\Plano;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Tests\TestCase;

class ComandosDeActivacaoTest extends TestCase
{
    use ComLicencaDeTeste;
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararLicencas(activo: false);
        $this->prepararEmissor();
        $this->congelarRelogioDeTeste();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function plano(): Plano
    {
        return app(CriarPlanoAction::class)->executar(new PlanoDTO(
            nome: 'Transição', modulos: ['financeiro'], validadeDias: 365, modalidade: Modalidade::ONLINE, limites: ['alunos' => 100],
        ));
    }

    private function segundaEscola(): string
    {
        return $this->escolaDeGestao('MOSI-000777', 'Escola Segunda', 'segunda.mositec.test')->codigo;
    }

    // --- licenciamento:gerar-chaves ---------------------------------------------------------

    public function test_gerar_chaves_mostra_um_par_valido_sem_tocar_na_bd_nem_no_disco(): void
    {
        // Não se conta os ficheiros do /tmp (partilhado com outros processos e testes: frágil). Procura-se antes, nos
        // ficheiros pequenos aí criados desde o arranque, o material da chave privada que o comando acabou de mostrar.
        $inicio = time() - 1;
        $planos = DB::table('licenciamento_planos')->count();
        DB::enableQueryLog();

        $this->assertSame(0, Artisan::call('licenciamento:gerar-chaves', ['--kid' => 'k9']));
        $saida = Artisan::output();

        $this->assertSame([], DB::getQueryLog(), 'não deve consultar a BD');
        DB::disableQueryLog();
        $this->assertStringContainsString('PRIVADA', $saida);
        $this->assertStringContainsString('Nada foi gravado', $saida);
        $this->assertSame($planos, DB::table('licenciamento_planos')->count());

        preg_match_all('/^[A-Za-z0-9+\/]{40,}={0,2}$/m', $saida, $m);
        $privada = $m[0][1] ?? null;
        $this->assertNotNull($privada, 'o comando devia mostrar a chave privada');

        foreach (glob(sys_get_temp_dir().'/*') ?: [] as $ficheiro) {
            if (is_file($ficheiro) && filesize($ficheiro) < 4096 && filemtime($ficheiro) >= $inicio) {
                $this->assertStringNotContainsString($privada, (string) file_get_contents($ficheiro), 'a chave privada não pode ficar em disco');
            }
        }
    }

    public function test_gerar_chaves_devolve_chaves_que_assinam_e_verificam(): void
    {
        Artisan::call('licenciamento:gerar-chaves');
        $saida = Artisan::output();

        preg_match_all('/^[A-Za-z0-9+\/]{40,}={0,2}$/m', $saida, $m);
        $this->assertCount(2, $m[0]);
        [$publica, $privada] = array_map('base64_decode', $m[0]);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($publica));
        $this->assertSame($publica, sodium_crypto_sign_publickey_from_secretkey($privada));
    }

    // --- licenciamento:escolas-sem-licenca ---------------------------------------------------

    public function test_escolas_sem_licenca_lista_e_sai_com_1(): void
    {
        $this->artisan('licenciamento:escolas-sem-licenca')
            ->expectsOutputToContain($this->tenant->codigo)
            ->assertExitCode(1);
    }

    public function test_escolas_sem_licenca_sai_com_0_quando_todas_tem(): void
    {
        $plano = $this->plano();
        app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, escola: $this->tenant->codigo), null);

        $this->artisan('licenciamento:escolas-sem-licenca')->assertExitCode(0);
    }

    public function test_escolas_sem_licenca_e_so_leitura(): void
    {
        $antes = [DireitosLocais::count(), LicencaEmitida::count(), EventoDeLicenca::count()];

        $this->artisan('licenciamento:escolas-sem-licenca')->assertExitCode(1);

        $this->assertSame($antes, [DireitosLocais::count(), LicencaEmitida::count(), EventoDeLicenca::count()]);
    }

    public function test_escolas_sem_licenca_apanha_a_expirada_alem_da_tolerancia_e_a_revogada(): void
    {
        $plano = $this->plano();
        $licenca = app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, escola: $this->tenant->codigo), null);

        $this->artisan('licenciamento:escolas-sem-licenca')->assertExitCode(0);

        app(SuspenderLicencaAction::class)->executar($licenca, 'Atraso', 1);
        $this->artisan('licenciamento:escolas-sem-licenca')->expectsOutputToContain('revogada')->assertExitCode(1);

        $this->congelarRelogioDeTeste('2030-01-01T00:00:00+00:00'); // muito depois de expirar + tolerância
        $this->artisan('licenciamento:escolas-sem-licenca')->assertExitCode(1);
    }

    // --- licenciamento:emitir-transicao --------------------------------------------------------

    public function test_emitir_transicao_emite_so_para_escolas_sem_licenca_valida_e_depois_o_preflight_da_0(): void
    {
        $plano = $this->plano();
        $segunda = $this->segundaEscola();
        // A primeira já tem licença válida: não pode ser tocada.
        $existente = app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, escola: $this->tenant->codigo), 3);
        $this->congelarRelogioDeTeste('2026-06-02T00:00:00+00:00');

        $this->artisan('licenciamento:escolas-sem-licenca')->expectsOutputToContain($segunda)->assertExitCode(1);

        $this->artisan('licenciamento:emitir-transicao', ['--plano' => $plano->id, '--dias' => 30])
            ->expectsOutputToContain($segunda)
            ->assertExitCode(0);

        $this->assertSame(2, LicencaEmitida::count());
        $this->assertSame($existente->id, DireitosLocais::query()->where('tenant_id', $this->tenant->id)->value('licenca_id'));
        $nova = LicencaEmitida::query()->where('escola', $segunda)->sole();
        $this->assertSame(Modalidade::ONLINE, $nova->modalidade);
        $this->assertTrue($nova->expira_em->equalTo(CarbonImmutable::parse('2026-07-02T00:00:00+00:00')));
        $evento = EventoDeLicenca::query()->where('licenca_id', $nova->id)->where('tipo', 'emitida')->sole();
        $this->assertNull($evento->super_admin_id);
        $this->assertSame('transição', $evento->motivo);

        $this->artisan('licenciamento:escolas-sem-licenca')->assertExitCode(0);
    }

    public function test_emitir_transicao_dry_run_so_lista(): void
    {
        $plano = $this->plano();

        $this->artisan('licenciamento:emitir-transicao', ['--plano' => $plano->id, '--dias' => 30, '--dry-run' => true])
            ->expectsOutputToContain($this->tenant->codigo)
            ->assertExitCode(0);

        $this->assertSame(0, LicencaEmitida::count());
        $this->assertSame(0, DireitosLocais::count());
    }

    public function test_emitir_transicao_respeita_o_filtro_de_escola(): void
    {
        $plano = $this->plano();
        $segunda = $this->segundaEscola();

        $this->artisan('licenciamento:emitir-transicao', ['--plano' => $plano->id, '--dias' => 30, '--escola' => [$segunda]])->assertExitCode(0);

        $this->assertSame([$segunda], LicencaEmitida::query()->pluck('escola')->all());
    }

    public function test_emitir_transicao_recusa_sem_emissor_e_nao_emite(): void
    {
        $plano = $this->plano();
        config(['licenciamento.emissor.chave_privada_ficheiro' => '']);

        $this->artisan('licenciamento:emitir-transicao', ['--plano' => $plano->id, '--dias' => 30])->assertExitCode(1);

        $this->assertSame(0, LicencaEmitida::count());
    }

    public function test_emitir_transicao_exige_plano_e_dias_validos(): void
    {
        $this->artisan('licenciamento:emitir-transicao')->assertExitCode(1);
        $this->artisan('licenciamento:emitir-transicao', ['--plano' => 99999, '--dias' => 30])->expectsOutputToContain('plano não existe')->assertExitCode(1);
        $this->artisan('licenciamento:emitir-transicao', ['--plano' => $this->plano()->id, '--dias' => 0])->assertExitCode(1);
        $this->assertSame(0, LicencaEmitida::count());
    }

    public function test_emitir_transicao_nao_desfaz_uma_revogacao(): void
    {
        $plano = $this->plano();
        $licenca = app(EmitirLicencaAction::class)->executar(new EmitirLicencaDTO(planoId: $plano->id, escola: $this->tenant->codigo), null);
        app(SuspenderLicencaAction::class)->executar($licenca, 'Atraso', 1);

        $this->artisan('licenciamento:emitir-transicao', ['--plano' => $plano->id, '--dias' => 30])->expectsOutputToContain('saltada')->assertExitCode(0);

        $this->assertSame(1, LicencaEmitida::count());
    }
}
