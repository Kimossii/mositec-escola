<?php

namespace Modules\Disciplina\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\Importacao;
use Modules\Disciplina\Models\Disciplina;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ImportacaoDeDisciplinasTest extends TestCase
{
    use RefreshDatabase;

    public function test_importa_disciplinas_pelo_mesmo_motor_e_sem_a_permissao_nao_entra(): void
    {
        Storage::fake('local');
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);

        $this->get('/importacoes/disciplinas/nova')->assertOk();
        $this->post('/importacoes/disciplinas', ['ficheiro' => UploadedFile::fake()->createWithContent('d.csv', "Código*;Nome*\nMAT;Matemática\nPOR;Português\nMAT;Outra\n")])->assertRedirect();
        $imp = Importacao::latest('id')->first();
        $this->post("/importacoes/{$imp->id}/confirmar");

        $this->assertSame(2, Disciplina::count());
        $this->assertSame(1, $imp->fresh()->com_erro);

        $prof = User::create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('x')]);
        $prof->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->first()->id);
        $this->actingAs($prof)->get('/importacoes/disciplinas/nova')->assertForbidden();
    }
}
