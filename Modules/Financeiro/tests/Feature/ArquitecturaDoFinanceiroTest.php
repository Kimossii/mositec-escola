<?php

namespace Modules\Financeiro\Tests\Feature;

use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * As camadas do módulo, cada coisa no seu sítio: Request (permissão e validação) → Controller fino → serviço de gestão →
 * Action (regra de negócio, com DTO quando há vários dados), e leituras em serviços de consulta. O `Request` genérico continua
 * a servir para os filtros das listagens (GET), como no resto do projecto. Se alguém puser lógica no sítio errado, falha aqui.
 */
class ArquitecturaDoFinanceiroTest extends TestCase
{
    /** @return list<class-string> */
    private function classes(string $pasta): array
    {
        $classes = [];
        foreach (glob(base_path("Modules/Financeiro/app/{$pasta}/*.php")) as $ficheiro) {
            $classes[] = 'Modules\\Financeiro\\'.str_replace('/', '\\', $pasta).'\\'.basename($ficheiro, '.php');
        }

        return $classes;
    }

    public function test_os_controllers_nao_validam_nao_consultam_nem_abortam_por_conta_propria(): void
    {
        $proibido = ['::query()', '->validate(', 'findOrFail', 'Rule::', 'abort_', 'DB::', 'Gate::'];
        $erros = [];

        foreach (glob(base_path('Modules/Financeiro/app/Http/Controllers/*.php')) as $ficheiro) {
            $codigo = file_get_contents($ficheiro);
            foreach ($proibido as $marca) {
                if (str_contains($codigo, $marca)) {
                    $erros[] = basename($ficheiro)." usa «{$marca}»: isso pertence a um Request, a um serviço ou a uma Action.";
                }
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_as_actions_recebem_um_dto_quando_ha_varios_dados(): void
    {
        $erros = [];

        foreach ($this->classes('Actions') as $classe) {
            $r = new ReflectionClass($classe);
            if (! $r->hasMethod('executar')) {
                continue;
            }
            $simples = array_filter($r->getMethod('executar')->getParameters(), fn ($p) => $p->getType() instanceof ReflectionNamedType && $p->getType()->isBuiltin());

            if (count($simples) > 2) {
                $erros[] = "{$classe}::executar recebe ".count($simples).' valores soltos; use um DTO.';
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    /** Colectores de resultado, que se vão enchendo durante a operação; não são dados de entrada. */
    private const COLECTORES = ['ResumoCopiaPlanosDTO'];

    public function test_os_dtos_sao_imutaveis(): void
    {
        $erros = [];

        foreach ($this->classes('DTO') as $classe) {
            if (in_array(class_basename($classe), self::COLECTORES, true)) {
                continue;
            }
            $r = new ReflectionClass($classe);
            foreach ($r->getProperties(\ReflectionProperty::IS_PUBLIC) as $propriedade) {
                if (! $propriedade->isReadOnly() && ! $r->isReadOnly()) {
                    $erros[] = "{$classe}::\${$propriedade->getName()} devia ser readonly.";
                }
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_cada_migracao_faz_uma_coisa_so(): void
    {
        $erros = [];

        foreach (glob(base_path('Modules/Financeiro/database/migrations/*.php')) as $ficheiro) {
            $codigo = file_get_contents($ficheiro);
            $criadas = preg_match_all('/Schema::create\(/', $codigo);
            $alteradas = count(array_unique(array_filter(array_map(fn ($m) => $m, (preg_match_all("/Schema::table\('([a-z_]+)'/", $codigo, $m) ? $m[1] : [])))));

            if ($criadas > 1 || ($criadas === 1 && $alteradas > 0) || $alteradas > 1) {
                $erros[] = basename($ficheiro).": {$criadas} tabelas criadas e {$alteradas} alteradas; uma migração faz uma coisa só.";
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }
}
