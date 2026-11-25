<?php

namespace Modules\Plataforma\Console;

use Illuminate\Console\Command;
use Modules\Plataforma\Actions\DesactivarDoisFactoresSuperAdminAction;
use Modules\Plataforma\Actions\RegistarAuditoriaAction;
use Modules\Plataforma\DTO\RegistoAuditoriaDTO;
use Modules\Plataforma\Models\SuperAdmin;
use Throwable;

/** Repõe (desactiva) o 2FA de um super admin que perdeu a aplicação e os códigos de recuperação. Sem lógica: delega nas Actions. */
class ReporDoisFactoresSuperAdminCommand extends Command
{
    protected $signature = 'mosi:plataforma:admin:2fa-reset
        {--email= : Email do super admin}';

    protected $description = 'Desactiva o 2FA de um super admin da Plataforma MosiTec (perdeu a aplicação e os códigos de recuperação)';

    public function handle(DesactivarDoisFactoresSuperAdminAction $desactivar, RegistarAuditoriaAction $auditoria): int
    {
        $email = trim((string) $this->option('email'));
        $email = mb_strtolower($email !== '' ? $email : trim((string) $this->ask('Email do super admin')));

        $admin = $email === '' ? null : SuperAdmin::query()->where('email', $email)->first();

        if ($admin === null) {
            $this->error('Super admin não encontrado.');

            return self::FAILURE;
        }

        if ($admin->two_factor_secret === null) {
            $this->warn('Este super admin não tem 2FA activo; nada foi alterado.');

            return self::SUCCESS;
        }

        try {
            $desactivar->executar($admin);
            $auditoria->executar(new RegistoAuditoriaDTO(null, 'super_admin.2fa_reposto', null, ['super_admin_id' => $admin->id]));
        } catch (Throwable $e) {
            report($e);
            $this->error('Falha inesperada (' . $e::class . '). Consulte os logs.');

            return self::FAILURE;
        }

        $this->info("2FA reposto para {$admin->email}. Pode entrar só com a senha e activá-lo de novo em Segurança.");

        return self::SUCCESS;
    }
}
