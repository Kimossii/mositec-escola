<?php

namespace Modules\Avaliacao\Tests\Feature;

use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * As camadas do módulo, cada coisa no seu sítio. Pedido (FormRequest: permissão e validação) → Controller fino → serviço de
 * gestão (monta o DTO) → Action (regra de negócio); leituras em serviços de consulta. Se alguém puser lógica no sítio errado,
 * falha aqui, antes de chegar à revisão.
 */
class ArquitecturaDaAvaliacaoTest extends TestCase
{
    /** @return list<class-string> */
    private function classes(string $pasta): array
    {
        $classes = [];
        foreach (glob(base_path("Modules/Avaliacao/app/{$pasta}/*.php")) as $ficheiro) {
            $classes[] = 'Modules\\Avaliacao\\'.str_replace('/', '\\', $pasta).'\\'.basename($ficheiro, '.php');
        }

        return $classes;
    }

    public function test_os_controllers_nao_validam_nao_consultam_nem_autorizam_por_conta_propria(): void
    {
        $proibido = ['::query()', 'Gate::', '->validate(', 'findOrFail', 'Rule::', 'abort_', 'DB::', '->authorize('];
        $erros = [];

        foreach (glob(base_path('Modules/Avaliacao/app/Http/Controllers/*.php')) as $ficheiro) {
            $codigo = file_get_contents($ficheiro);
            foreach ($proibido as $marca) {
                if (str_contains($codigo, $marca)) {
                    $erros[] = basename($ficheiro)." usa «{$marca}»: isso pertence a um Request, a um serviço ou a uma Action.";
                }
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_os_controllers_recebem_form_requests_e_nunca_o_request_generico(): void
    {
        $erros = [];

        foreach ($this->classes('Http/Controllers') as $classe) {
            foreach ((new ReflectionClass($classe))->getMethods() as $metodo) {
                if ($metodo->class !== $classe || $metodo->isConstructor()) {
                    continue;
                }
                foreach ($metodo->getParameters() as $parametro) {
                    $tipo = $parametro->getType();
                    if ($tipo instanceof ReflectionNamedType && $tipo->getName() === \Illuminate\Http\Request::class) {
                        $erros[] = "{$classe}::{$metodo->getName()} recebe o Request genérico; crie um FormRequest.";
                    }
                }
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_todo_o_pedido_estende_o_pedido_base_e_decide_a_permissao(): void
    {
        $erros = [];

        foreach ($this->classes('Http/Requests') as $classe) {
            $r = new ReflectionClass($classe);
            if ($r->isFinal() || $r->isAbstract()) {
                continue; // MensagensDeAvaliacao
            }
            if (! $r->isSubclassOf(\App\Http\Requests\BaseRequest::class)) {
                $erros[] = "{$classe} não estende BaseRequest.";
            }
            $codigo = file_get_contents($r->getFileName());
            if (! str_contains($codigo, "->can('nota.")) {
                $erros[] = "{$classe} não decide a permissão em authorize().";
            }
            if (! str_contains($codigo, 'MensagensDeAvaliacao::todas()')) {
                $erros[] = "{$classe} não tem mensagens em português.";
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_as_actions_recebem_um_dto_quando_ha_varios_dados(): void
    {
        $erros = [];

        foreach ($this->classes('Actions') as $classe) {
            $metodo = (new ReflectionClass($classe))->getMethod('executar');
            $simples = array_filter($metodo->getParameters(), fn ($p) => $p->getType() instanceof ReflectionNamedType && $p->getType()->isBuiltin());

            if (count($simples) > 2) {
                $erros[] = "{$classe}::executar recebe ".count($simples).' valores soltos; use um DTO.';
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_os_dtos_sao_imutaveis(): void
    {
        $erros = [];

        foreach ($this->classes('DTO') as $classe) {
            $r = new ReflectionClass($classe);
            if (! $r->isReadOnly() && ! $r->isFinal()) {
                $erros[] = "{$classe} devia ser readonly.";
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_os_servicos_de_gestao_so_falam_com_actions_e_nao_com_a_base_de_dados_directamente(): void
    {
        $erros = [];

        foreach (['GestaoMiniPautaService', 'GestaoConfiguracaoAvaliacaoService', 'GestaoPautaService'] as $servico) {
            $codigo = file_get_contents(base_path("Modules/Avaliacao/app/Services/{$servico}.php"));
            foreach (['DB::', 'Gate::', '->validate('] as $marca) {
                if (str_contains($codigo, $marca)) {
                    $erros[] = "{$servico} usa «{$marca}»: a regra de negócio vive na Action.";
                }
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }
}
