<?php

namespace Tests\Feature\Arquitectura;

use Tests\TestCase;

/**
 * Toda a Action de negócio regista o que faz (e falha com registo) através do LogService do Core — `comLog()`, o try/catch
 * certo: não engole o erro, não o regista duas vezes e ninguém precisa de se lembrar de o escrever. Uma Action nova que
 * esqueça isto falha aqui. As excepções são as que não são uma operação de negócio (auditoria, arranque de dados).
 */
class ActionsComRegistoTest extends TestCase
{
    private const EXCEPCOES = [
        'RegistarAuditoriaAction',            // é ela própria o registo de auditoria da Plataforma
        'UsuarioAction',                      // fachada antiga, só delega
        'SincronizarPerfisDeSistemaAction',   // arranque de dados, corre em seeders/provisionamento
        'CriarTiposDocumentoPadraoAction',    // arranque de dados
        'GarantirAdministradorEfetivoAction', // verificação, não escreve
    ];

    public function test_todas_as_actions_usam_o_log_service_do_core(): void
    {
        $semRegisto = [];

        foreach (glob(base_path('Modules/*/app/Actions/*.php')) as $ficheiro) {
            $nome = basename($ficheiro, '.php');
            $codigo = file_get_contents($ficheiro);

            if (in_array($nome, self::EXCEPCOES, true) || preg_match('/^(abstract\s+)?(interface|trait)\s/m', $codigo) || preg_match('/\babstract\s+class\b/', $codigo)) {
                continue;
            }

            if (! str_contains($codigo, 'use LogService;')) {
                $semRegisto[] = str_replace(base_path().'/', '', $ficheiro);
            }
        }

        $this->assertSame([], $semRegisto, "Actions sem LogService (use Modules\\Core\\Traits\\LogService e comLog()):\n".implode("\n", $semRegisto));
    }
}
