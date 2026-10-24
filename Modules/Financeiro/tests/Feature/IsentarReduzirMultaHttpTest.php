<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\OrigemRevisaoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\PropinaMultaRevisao;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComMultasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Isenção/redução manual da multa (spec Propinas §15.4, permissão `propina.isentar-multa`). Moeda AOA (2 casas).
 */
class IsentarReduzirMultaHttpTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComMultasFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin.multa@example.com', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        return $user;
    }

    /** @return array{0: Propina, 1: PropinaMulta} */
    private function propinaComMulta(int $valorMulta = 25_000, array $atributosMulta = []): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        return [$propina, $this->multa($propina, $valorMulta, $atributosMulta)];
    }

    private function url(Propina $propina): string
    {
        return "/financeiro/propinas/{$propina->id}/multa";
    }

    public function test_reduz_a_multa(): void
    {
        [$propina, $multa] = $this->propinaComMulta();

        $this->actingAs($this->admin())->from($this->url($propina))
            ->patch($this->url($propina), ['novo_valor' => '100', 'motivo' => 'Redução aprovada pela direcção'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $multa->refresh();
        $this->assertSame(10_000, $multa->valor->unidadesMenores());
        $this->assertSame(EstadoMulta::REDUZIDA, $multa->estado);
        $this->assertTrue($multa->bloqueada_automatico);

        $revisao = PropinaMultaRevisao::sole();
        $this->assertSame(OrigemRevisaoMulta::MANUAL, $revisao->origem);
        $this->assertSame(25_000, $revisao->valor_anterior);
        $this->assertSame(10_000, $revisao->valor_novo);
        $this->assertSame('Redução aprovada pela direcção', $revisao->motivo);
    }

    public function test_isenta_totalmente_com_valor_zero(): void
    {
        [$propina, $multa] = $this->propinaComMulta();

        $this->actingAs($this->admin())->patch($this->url($propina), ['novo_valor' => '0', 'motivo' => 'Isenção total aprovada'])->assertRedirect()->assertSessionHasNoErrors();

        $multa->refresh();
        $this->assertSame(0, $multa->valor->unidadesMenores());
        $this->assertSame(EstadoMulta::ISENTA, $multa->estado);
        $this->assertTrue($multa->bloqueada_automatico);
    }

    public function test_isenta_ao_valor_ja_pago(): void
    {
        [$propina, $multa] = $this->propinaComMulta();
        $multa->forceFill(['valor_pago' => 10_000])->save();

        $this->actingAs($this->admin())->patch($this->url($propina), ['novo_valor' => '100', 'motivo' => 'Isento do remanescente'])->assertRedirect()->assertSessionHasNoErrors();

        $multa->refresh();
        $this->assertSame(10_000, $multa->valor->unidadesMenores());
        $this->assertSame(EstadoMulta::ISENTA, $multa->estado);
    }

    public function test_novo_valor_abaixo_do_pago_e_recusado(): void
    {
        [$propina, $multa] = $this->propinaComMulta();
        $multa->forceFill(['valor_pago' => 10_000])->save();

        $this->actingAs($this->admin())->patch($this->url($propina), ['novo_valor' => '50', 'motivo' => 'Motivo válido'])
            ->assertSessionHasErrors('novo_valor');
    }

    public function test_novo_valor_acima_do_actual_e_recusado(): void
    {
        [$propina] = $this->propinaComMulta();

        $this->actingAs($this->admin())->patch($this->url($propina), ['novo_valor' => '300', 'motivo' => 'Motivo válido'])
            ->assertSessionHasErrors('novo_valor');
    }

    public function test_multa_anulada_e_recusada(): void
    {
        [$propina, $multa] = $this->propinaComMulta();
        $multa->forceFill(['estado' => EstadoMulta::ANULADA])->save();

        $this->actingAs($this->admin())->patch($this->url($propina), ['novo_valor' => '100', 'motivo' => 'Motivo válido'])
            ->assertSessionHasErrors('multa');
    }

    public function test_propina_sem_multa_e_recusada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        $this->actingAs($this->admin())->patch($this->url($propina), ['novo_valor' => '100', 'motivo' => 'Motivo válido'])
            ->assertSessionHasErrors('multa');
    }

    public function test_motivo_e_obrigatorio(): void
    {
        [$propina] = $this->propinaComMulta();

        $this->actingAs($this->admin())->patch($this->url($propina), ['novo_valor' => '100', 'motivo' => ''])
            ->assertSessionHasErrors('motivo');
    }

    public function test_professor_nao_pode_isentar(): void
    {
        [$propina] = $this->propinaComMulta();
        $professor = User::create(['name' => 'P', 'email' => 'prof.multa@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->actingAs($professor)->patch($this->url($propina), ['novo_valor' => '100', 'motivo' => 'Motivo válido'])
            ->assertForbidden();
    }
}
