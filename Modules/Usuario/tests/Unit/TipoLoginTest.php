<?php

namespace Modules\Usuario\Tests\Unit;

use Modules\Usuario\Enums\TipoLogin;
use Tests\TestCase;

class TipoLoginTest extends TestCase
{
    public function test_from_label_mapeia_email_e_matricula(): void
    {
        $this->assertSame(TipoLogin::EMAIL, TipoLogin::fromLabel('email'));
        $this->assertSame(TipoLogin::MATRICULA, TipoLogin::fromLabel('matricula'));
    }
}
