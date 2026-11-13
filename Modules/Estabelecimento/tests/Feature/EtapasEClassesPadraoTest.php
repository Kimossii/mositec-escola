<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * As etapas de ensino da escola e as classes padrão: acrescentar uma etapa dá-lhe as suas classes (Creche a 9.ª), retirá-la
 * retira-as desde que nenhuma tenha informação ligada. O que a escola mudou depois nunca se desfaz.
 */
class EtapasEClassesPadraoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);
        Estabelecimento::current()->forceFill(['tipo' => 1])->save();
    }

    /** @param list<EtapaEnsinoEnum> $etapas */
    private function gravarEtapas(array $etapas)
    {
        return $this->put('/estabelecimento', [
            'nome' => 'Escola',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => array_map(fn (EtapaEnsinoEnum $e) => $e->value, $etapas),
        ]);
    }

    /** @return list<string> */
    private function codigos(): array
    {
        return NivelAcademico::query()->orderBy('ordem')->pluck('codigo')->all();
    }

    private function turmaNaClasse(NivelAcademico $nivel): Turma
    {
        $estabelecimento = Estabelecimento::current();
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $turno = Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'GER', 'nome' => 'Geral']);

        return Turma::create(['ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'turno_id' => $turno->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
    }

    public function test_as_etapas_acrescentadas_recebem_as_suas_classes_padrao(): void
    {
        $this->gravarEtapas([EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRE_ESCOLAR, EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors();

        // Primário = 1.ª a 6.ª; o 1.º Ciclo (7.ª a 9.ª) só vem quando a escola o declara.
        $this->assertSame(['CRECHE_I', 'CRECHE_II', 'PRE_I', 'PRE_II', 'PRE_III', 'PRI_1', 'PRI_2', 'PRI_3', 'PRI_4', 'PRI_5', 'PRI_6'], $this->codigos());
        $this->assertSame('1.ª Classe', NivelAcademico::where('codigo', 'PRI_1')->value('nome'));
        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, NivelAcademico::where('codigo', 'PRI_6')->first()->etapa_ensino);
    }

    public function test_acrescentar_o_1_ciclo_depois_cria_so_a_7_a_9_e_o_secundario_nao_cria_nada(): void
    {
        $this->gravarEtapas([EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors();
        $this->gravarEtapas([EtapaEnsinoEnum::PRIMARIO, EtapaEnsinoEnum::PRIMEIRO_CICLO, EtapaEnsinoEnum::SECUNDARIO])->assertSessionHasNoErrors();

        $this->assertSame(['PRI_1', 'PRI_2', 'PRI_3', 'PRI_4', 'PRI_5', 'PRI_6', 'SEC_7', 'SEC_8', 'SEC_9'], $this->codigos()); // sem a 10.ª a 13.ª
        $this->assertSame(EtapaEnsinoEnum::PRIMEIRO_CICLO, NivelAcademico::where('codigo', 'SEC_8')->first()->etapa_ensino);
    }

    public function test_so_cria_quando_a_etapa_e_acrescentada_e_nunca_desfaz_o_que_a_escola_mudou(): void
    {
        $this->gravarEtapas([EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors();
        NivelAcademico::where('codigo', 'PRI_1')->first()->update(['nome' => 'Primeira Classe']);
        NivelAcademico::where('codigo', 'PRI_6')->first()->delete();

        $this->gravarEtapas([EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors(); // gravar outra vez, sem etapas novas

        $this->assertSame('Primeira Classe', NivelAcademico::where('codigo', 'PRI_1')->value('nome'));
        $this->assertNotContains('PRI_6', $this->codigos());
        $this->assertSame(5, NivelAcademico::count());
    }

    public function test_uma_classe_da_escola_com_o_mesmo_nome_nao_se_duplica(): void
    {
        NivelAcademico::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => '1C', 'nome' => '1.ª Classe', 'ordem' => 1, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);

        $this->gravarEtapas([EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors();

        $this->assertSame(1, NivelAcademico::where('nome', '1.ª Classe')->count());
        $this->assertContains('1C', $this->codigos());
        $this->assertNotContains('PRI_1', $this->codigos());
    }

    public function test_retirar_uma_etapa_sem_informacao_ligada_retira_as_suas_classes(): void
    {
        $this->gravarEtapas([EtapaEnsinoEnum::PRE_ESCOLAR, EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors();

        $this->gravarEtapas([EtapaEnsinoEnum::PRE_ESCOLAR])->assertSessionHasNoErrors();

        $this->assertSame(['PRE_I', 'PRE_II', 'PRE_III'], $this->codigos());
        $this->assertSame(['PRE_ESCOLAR'], Estabelecimento::current()->etapasEnsino()->get()->map(fn ($e) => $e->etapa_ensino->name)->all());
    }

    public function test_retirar_uma_etapa_com_uma_classe_com_turmas_nao_mexe_em_nada(): void
    {
        $this->gravarEtapas([EtapaEnsinoEnum::PRE_ESCOLAR, EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors();
        $this->turmaNaClasse(NivelAcademico::where('codigo', 'PRI_3')->first());

        $this->gravarEtapas([EtapaEnsinoEnum::PRE_ESCOLAR])->assertSessionHasErrors('etapas_ensino');

        // Nenhuma classe do Primário saiu, nem as que não tinham turmas, e a etapa continua declarada.
        $this->assertCount(6, NivelAcademico::where('etapa_ensino', EtapaEnsinoEnum::PRIMARIO)->get());
        $this->assertCount(2, Estabelecimento::current()->etapasEnsino()->get());
    }

    public function test_voltar_a_acrescentar_a_etapa_reactiva_as_classes_retiradas_sem_duplicar(): void
    {
        $this->gravarEtapas([EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors();
        $this->gravarEtapas([EtapaEnsinoEnum::CRECHE])->assertSessionHasNoErrors();
        $this->assertSame(['CRECHE_I', 'CRECHE_II'], $this->codigos());

        $this->gravarEtapas([EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::PRIMARIO])->assertSessionHasNoErrors();

        $this->assertCount(8, NivelAcademico::all());
        $this->assertSame(6, NivelAcademico::where('etapa_ensino', EtapaEnsinoEnum::PRIMARIO)->count());
    }
}
