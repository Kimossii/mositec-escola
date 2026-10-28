<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Modules\Usuario\Models\User;
use RuntimeException;
use Tests\TestCase;

class ExcepcoesComContextoTest extends TestCase
{
    use RefreshDatabase;

    public function test_excepcao_nao_tratada_e_registada_com_escola_utilizador_e_pedido_sem_query_string(): void
    {
        Route::middleware(['web', 'auth'])->get('/__rebenta', fn () => throw new RuntimeException('rebentou'));
        $user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => Hash::make('x')]);
        Log::spy();

        $this->actingAs($user)->get('/__rebenta?token=segredo')->assertStatus(500);

        Log::shouldHaveReceived('error')->withArgs(fn ($mensagem, array $contexto = []) => ($contexto['tenant_id'] ?? null) === $this->tenant->id
            && ($contexto['user_id'] ?? null) === $user->id
            && ($contexto['metodo'] ?? null) === 'GET'
            && ($contexto['caminho'] ?? null) === '/__rebenta'
            && ! str_contains(json_encode($contexto), 'segredo'))->atLeast()->once();
    }

    public function test_excepcoes_de_regra_de_negocio_entram_como_warning_e_as_inesperadas_como_error(): void
    {
        Log::spy();
        $emissor = new class
        {
            use \Modules\Core\Traits\LogService;

            public function registar(\Throwable $e): void
            {
                $this->logException($e, 'x');
            }
        };

        $emissor->registar(new \Modules\Plataforma\Exceptions\CredenciaisInvalidas);
        $emissor->registar(new \DomainException('regra'));
        $emissor->registar(new RuntimeException('avaria'));

        Log::shouldHaveReceived('warning')->twice();
        Log::shouldHaveReceived('error')->once();
    }
}
