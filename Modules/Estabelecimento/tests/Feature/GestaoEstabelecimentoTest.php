<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GestaoEstabelecimentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_cria_o_estabelecimento_ao_atualizar_dados_pela_primeira_vez(): void
    {
        $this->actingAsAdmin();

        $response = $this->put('/estabelecimento', [
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
            'nif' => '5000123456',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('estabelecimentos', [
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_descricao' => 'Privado',
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'tipo_ensino_descricao' => 'Ensino Geral',
            'is_active' => true,
        ]);
    }

    public function test_atualiza_o_estabelecimento_atual_em_vez_de_duplicar(): void
    {
        $this->actingAsAdmin();

        Estabelecimento::create(['nome' => 'Escola Antiga', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);

        $response = $this->put('/estabelecimento', [
            'nome' => 'Escola Renomeada',
            'tipo' => TipoEstabelecimentoEnum::COOPERATIVO->value,
            'tipo_ensino' => TipoEnsinoEnum::TECNICO->value,
            'etapas_ensino' => [EtapaEnsinoEnum::SECUNDARIO->value],
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Estabelecimento::count());
        $this->assertDatabaseHas('estabelecimentos', [
            'nome' => 'Escola Renomeada',
            'tipo' => TipoEstabelecimentoEnum::COOPERATIVO->value,
        ]);
    }

    public function test_atualiza_tipo_ensino_com_valores_validos(): void
    {
        $this->actingAsAdmin();

        foreach ([1 => 'Ensino Geral', 2 => 'Ensino Técnico', 3 => 'Ensino Universitário'] as $valor => $descricao) {
            $payload = [
                'nome' => 'Escola Teste',
                'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
                'tipo_ensino' => $valor,
            ];
            if ($valor !== TipoEnsinoEnum::UNIVERSITARIO->value) {
                $payload['etapas_ensino'] = [EtapaEnsinoEnum::PRIMARIO->value];
            }

            $this->put('/estabelecimento', $payload)->assertSessionHasNoErrors();

            $this->assertDatabaseHas('estabelecimentos', [
                'tipo_ensino' => $valor,
                'tipo_ensino_descricao' => $descricao,
            ]);
        }
    }

    public function test_rejeita_tipo_ensino_invalido(): void
    {
        $this->actingAsAdmin();

        $this->put('/estabelecimento', [
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => 99,
        ])->assertSessionHasErrors('tipo_ensino');
    }

    public function test_universitario_forca_etapa_superior_independente_do_enviado(): void
    {
        $this->actingAsAdmin();

        $this->put('/estabelecimento', [
            'nome' => 'Instituto Superior',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::UNIVERSITARIO->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
        ])->assertSessionHasNoErrors();

        $etapas = Estabelecimento::current()->etapasEnsino()->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all();
        $this->assertSame([EtapaEnsinoEnum::SUPERIOR->value], $etapas);
    }

    public function test_geral_ou_tecnico_exige_etapas_ensino_nao_vazio(): void
    {
        $this->actingAsAdmin();

        $this->put('/estabelecimento', [
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
        ])->assertSessionHasErrors('etapas_ensino');
    }

    public function test_universitario_aceita_o_payload_real_que_o_frontend_envia(): void
    {
        $this->actingAsAdmin();

        $this->put('/estabelecimento', [
            'nome' => 'Instituto Superior',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::UNIVERSITARIO->value,
            'etapas_ensino' => [EtapaEnsinoEnum::SUPERIOR->value],
        ])->assertSessionHasNoErrors();

        $etapas = Estabelecimento::current()->etapasEnsino()->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all();
        $this->assertSame([EtapaEnsinoEnum::SUPERIOR->value], $etapas);
    }

    public function test_remove_etapa_sem_niveis_academicos_associados(): void
    {
        $this->actingAsAdmin();
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola', 'tipo' => 1, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);

        $this->put('/estabelecimento', [
            'nome' => 'Escola',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
        ])->assertSessionHasNoErrors();

        $etapas = $estabelecimento->etapasEnsino()->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all();
        $this->assertSame([EtapaEnsinoEnum::PRIMARIO->value], $etapas);
    }

    public function test_remove_etapa_com_niveis_academicos_associados_falha(): void
    {
        $this->actingAsAdmin();
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola', 'tipo' => 1, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);
        \Modules\Turma\Models\NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '10C',
            'nome' => '10ª Classe',
            'ordem' => 10,
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
        ]);

        $this->put('/estabelecimento', [
            'nome' => 'Escola',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
        ])->assertSessionHasErrors('etapas_ensino');

        $this->assertDatabaseHas('estabelecimento_etapas_ensino', [
            'estabelecimento_id' => $estabelecimento->id,
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO->value,
        ]);
    }

    public function test_utilizador_sem_permissao_nao_acede_ao_estabelecimento(): void
    {
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($semPermissao);

        $response = $this->get('/estabelecimento');

        $response->assertForbidden();
    }

    public function test_pagina_dados_da_escola_devolve_o_estabelecimento_atual(): void
    {
        $this->actingAsAdmin();

        Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);

        $response = $this->get('/estabelecimento');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Estabelecimento/DadosDaEscola')
            ->where('estabelecimento.nome', 'Escola Exemplo')
        );
    }

    public function test_pagina_dados_da_escola_expoe_as_etapas_de_ensino_configuradas(): void
    {
        $this->actingAsAdmin();

        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);

        $this->get('/estabelecimento')->assertInertia(fn ($page) => $page
            ->component('Estabelecimento/DadosDaEscola')
            ->where('etapasEnsino', [EtapaEnsinoEnum::PRIMARIO->value, EtapaEnsinoEnum::SECUNDARIO->value])
        );
    }

    public function test_pagina_aparencia_devolve_o_estabelecimento_atual(): void
    {
        $this->actingAsAdmin();

        Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);

        $response = $this->get('/estabelecimento/aparencia');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Estabelecimento/Aparencia')
            ->where('estabelecimento.nome', 'Escola Exemplo')
        );
    }

    public function test_atualiza_o_logotipo_do_estabelecimento_atual(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);

        $response = $this->post('/estabelecimento/logotipo', [
            'logotipo' => UploadedFile::fake()->image('logo.png'),
        ]);

        $response->assertRedirect();
        $estabelecimento->refresh();
        $this->assertNotNull($estabelecimento->logotipo_path);
        Storage::disk('public')->assertExists($estabelecimento->logotipo_path);
    }

    public function test_nao_atualiza_logotipo_sem_estabelecimento_cadastrado(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $response = $this->post('/estabelecimento/logotipo', [
            'logotipo' => UploadedFile::fake()->image('logo.png'),
        ]);

        $response->assertSessionHasErrors('estabelecimento');
    }

    public function test_etapas_ensino_relaciona_com_estabelecimento_e_sincroniza_descricao(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => 1, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);

        $etapa = EstabelecimentoEtapaEnsino::create([
            'estabelecimento_id' => $estabelecimento->id,
            'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO,
        ]);

        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, $etapa->etapa_ensino);
        $this->assertSame('Ensino Primário', $etapa->etapa_ensino_descricao);
        $this->assertCount(1, $estabelecimento->etapasEnsino);
        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, $estabelecimento->etapasEnsino->first()->etapa_ensino);
    }
}
