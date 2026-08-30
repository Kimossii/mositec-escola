<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Models\Role;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_semeia_os_5_perfis(): void
    {
        (new RoleSeeder())->run();

        $this->assertSame(5, Role::count());
        $this->assertTrue(Role::where('descricao', 'Encarregado')->exists());
        $this->assertTrue(Role::where('descricao', 'Admin escola')->exists());
    }
}
