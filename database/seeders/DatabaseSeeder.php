<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Autenticacao\Database\Seeders\AdminUserSeeder;
use Modules\Core\Database\Seeders\HorarioSeeder;
use Modules\Core\Tenancy\TenantContext;
use Modules\Curso\Database\Seeders\CursoSeeder;
use Modules\Disciplina\Database\Seeders\DisciplinaSeeder;
use Modules\Infraestrutura\Database\Seeders\SalaSeeder;
use Modules\Permissao\Actions\SincronizarPerfisDeSistemaAction;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Plataforma\Database\Seeders\PlataformaDesenvolvimentoSeeder;
use Modules\Tenant\Database\Seeders\TenantDesenvolvimentoSeeder;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Database\Seeders\TurnoSeeder;
use Modules\Usuario\Actions\CriarTiposDocumentoPadraoAction;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * 1. Catálogo global (módulos e acções de permissão): uma vez por instalação.
     * 2. Em desenvolvimento, os dois tenants, criados pelo provisioning (CriarTenantAction).
     * 3. Em desenvolvimento, o super admin da Plataforma (global).
     * 4. Em desenvolvimento, dentro de CADA tenant: administrador com senha conhecida e dados de demonstração.
     *
     * Em produção só corre o passo 1: as escolas criam-se com `php artisan mosi:tenant:create`.
     */
    public function run(): void
    {
        $this->call([
            ModuloSeeder::class,
            AcaoSeeder::class,
        ]);

        if (! app()->environment('local', 'testing')) {
            return;
        }

        $this->call(TenantDesenvolvimentoSeeder::class);
        // Operador da Plataforma (global, sem tenant): fora do ciclo por tenant.
        $this->call(PlataformaDesenvolvimentoSeeder::class);

        foreach (TenantDesenvolvimentoSeeder::TENANTS as $definicao) {
            $tenant = Tenant::query()->where('codigo', $definicao['codigo'])->first();

            // Ausente só em TENANCY_MODO=unico (o segundo tenant não se cria).
            if ($tenant === null) {
                continue;
            }

            app(TenantContext::class)->executarComo($tenant->paraTenantAtual(), function () use ($definicao) {
                // Re-sincroniza o que o provisioning criou (idempotente): um db:seed propaga alterações
                // do mapa de permissões e dos tipos de documento a tenants já existentes.
                app(SincronizarPerfisDeSistemaAction::class)->executar();
                app(CriarTiposDocumentoPadraoAction::class)->executar();

                $this->callWith(AdminUserSeeder::class, [
                    'email' => $definicao['admin_email'],
                    'nome' => $definicao['admin_nome'],
                ]);

                $this->call([
                    DisciplinaSeeder::class,
                    CursoSeeder::class,
                    HorarioSeeder::class,
                    TurnoSeeder::class,
                    SalaSeeder::class,
                ]);
            });
        }
    }
}
