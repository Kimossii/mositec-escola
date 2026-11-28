<?php

namespace Modules\Core\Actions\Email;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\Comunicacao\Email\Dominio\ClienteDeDominios;
use Modules\Core\Comunicacao\Email\Dominio\ClienteDeDominiosIndisponivel;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Models\DominioEmail;

/** Regista (ou troca) o domínio de envio da escola no fornecedor e guarda os registos DNS a criar. Fica «por verificar» e inactivo. */
class RegistarDominioDeEmailAction
{
    public function __construct(private ClienteDeDominios $cliente, private RegistarAuditoriaTenantAction $auditoria) {}

    /** @throws ValidationException */
    public function executar(string $dominio, ?string $remetenteNome, string $remetenteLocal = 'noreply'): DominioEmail
    {
        $dominio = strtolower(trim($dominio));
        $local = strtolower(trim($remetenteLocal)) ?: 'noreply';

        $this->validar($dominio, $local);
        $this->recusarSeBloqueado();

        try {
            $registado = $this->cliente->registar($dominio);
        } catch (ClienteDeDominiosIndisponivel $e) {
            throw ValidationException::withMessages(['dominio' => $e->getMessage()]);
        }

        return DB::transaction(function () use ($dominio, $local, $remetenteNome, $registado) {
            $existente = DominioEmail::query()->first();
            $antes = $existente === null ? [] : ['dominio' => $existente->dominio, 'estado' => $existente->estado->value, 'activo' => $existente->activo];

            $d = $existente ?? new DominioEmail;
            $d->fill([
                'dominio' => $dominio,
                'id_externo' => $registado['id'],
                'remetente_nome' => $remetenteNome !== null && trim($remetenteNome) !== '' ? trim($remetenteNome) : null,
                'remetente_local' => $local,
                'registos' => $registado['registos'],
                'estado' => EstadoConfiguracaoEmail::POR_VERIFICAR,
                'activo' => false,
                'verificado_em' => null,
                'ultimo_erro' => null,
            ])->save();

            $this->auditoria->executar(new AuditoriaTenantDTO('dominio_email.registado', 'dominio_email', $d->id, $antes, ['dominio' => $dominio, 'estado' => 0, 'activo' => false]));

            return $d;
        });
    }

    private function recusarSeBloqueado(): void
    {
        if (DominioEmail::query()->value('bloqueada_pela_plataforma')) {
            throw ValidationException::withMessages(['dominio' => 'A MosiTec bloqueou o envio por domínio próprio desta escola. Contacte a MosiTec.']);
        }
    }

    private function validar(string $dominio, string $local): void
    {
        if (! preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $dominio)) {
            throw ValidationException::withMessages(['dominio' => 'Indique um domínio válido, por exemplo escola.ao (sem http:// nem endereços IP).']);
        }

        // Nunca o domínio central da plataforma (nem um subdomínio dele): a escola não pode enviar em nome da MosiTec.
        foreach ($this->dominiosDaPlataforma() as $central) {
            if ($dominio === $central || str_ends_with($dominio, '.'.$central)) {
                throw ValidationException::withMessages(['dominio' => 'Este domínio pertence à plataforma e não pode ser usado.']);
            }
        }

        if (! preg_match('/^[a-z0-9._-]{1,64}$/', $local)) {
            throw ValidationException::withMessages(['remetente_local' => 'Use só letras, números, ponto, hífen ou sublinhado.']);
        }
    }

    /** @return list<string> */
    private function dominiosDaPlataforma(): array
    {
        $hosts = [parse_url((string) config('app.url'), PHP_URL_HOST), substr(strrchr((string) config('mail.from.address'), '@') ?: '', 1)];

        return array_values(array_filter(array_map(fn ($h) => is_string($h) && $h !== '' ? strtolower($h) : null, $hosts)));
    }
}
