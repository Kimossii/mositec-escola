<?php

namespace Modules\Permissao\Tests\Unit;

use Modules\Permissao\Enums\Modulo;
use Tests\TestCase;

class ModuloEnumTest extends TestCase
{
    public function test_slug_e_fromslug_fazem_round_trip(): void
    {
        foreach (Modulo::cases() as $modulo) {
            $this->assertSame($modulo, Modulo::fromSlug($modulo->slug()));
        }
    }

    public function test_fromslug_devolve_null_para_slug_desconhecido(): void
    {
        $this->assertNull(Modulo::fromSlug('inexistente'));
    }

    public function test_slugs_especificos(): void
    {
        $this->assertSame('horario', Modulo::HORARIO->slug());
        $this->assertSame('turmas', Modulo::TURMAS->slug());
        $this->assertSame('ano-lectivo', Modulo::ANO_LECTIVO->slug());
        $this->assertSame('estabelecimento', Modulo::ESTABELECIMENTO->slug());
        $this->assertSame('curso', Modulo::CURSO->slug());
    }

    public function test_tryfrom_nativo_devolve_null_para_int_desconhecido(): void
    {
        $this->assertNull(Modulo::tryFrom(999));
        $this->assertSame(Modulo::HORARIO, Modulo::tryFrom(11));
    }

    public function test_plano_curricular_slug_e_label(): void
    {
        $this->assertSame('plano-curricular', Modulo::PLANO_CURRICULAR->slug());
        $this->assertSame('Plano Curricular', Modulo::PLANO_CURRICULAR->label());
        $this->assertSame(Modulo::PLANO_CURRICULAR, Modulo::fromSlug('plano-curricular'));
    }

    public function test_documento_pessoa_slug_e_label(): void
    {
        $this->assertSame('documento-pessoa', Modulo::DOCUMENTO_PESSOA->slug());
        $this->assertSame('Documento Pessoa', Modulo::DOCUMENTO_PESSOA->label());
        $this->assertSame(Modulo::DOCUMENTO_PESSOA, Modulo::fromSlug('documento-pessoa'));
    }

    public function test_senha_utilizador_slug_e_label(): void
    {
        $this->assertSame('senha-utilizador', Modulo::SENHA_UTILIZADOR->slug());
        $this->assertSame('Senha de Utilizador', Modulo::SENHA_UTILIZADOR->label());
        $this->assertSame(Modulo::SENHA_UTILIZADOR, Modulo::fromSlug('senha-utilizador'));
    }

    public function test_propina_slug_e_label(): void
    {
        $this->assertSame('propina', Modulo::PROPINA->slug());
        $this->assertSame('Propina', Modulo::PROPINA->label());
        $this->assertSame(Modulo::PROPINA, Modulo::fromSlug('propina'));
        $this->assertSame(Modulo::PROPINA, Modulo::tryFrom(22));
    }
}
