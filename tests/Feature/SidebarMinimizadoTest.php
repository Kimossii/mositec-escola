<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SidebarMinimizadoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        unset($_COOKIE['sidebar_minimize_state']);

        parent::tearDown();
    }

    public function test_o_sidebar_minimizado_persiste_ao_recarregar_a_pagina(): void
    {
        $user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => Hash::make('x')]);

        // O tema grava o cookie no browser; ao recarregar, o servidor tem de devolver o body já minimizado.
        $_COOKIE['sidebar_minimize_state'] = 'on';
        $minimizado = $this->actingAs($user)->get('/')->getContent();
        $this->assertMatchesRegularExpression('/<body[^>]*data-kt-app-sidebar-minimize="on"/s', $minimizado);
        $this->assertMatchesRegularExpression('/id="kt_app_sidebar_toggle"[^>]*data-kt-toggle-state="active"/s', $minimizado);

        $_COOKIE['sidebar_minimize_state'] = 'off';
        $normal = $this->actingAs($user)->get('/')->getContent();
        $this->assertDoesNotMatchRegularExpression('/<body[^>]*data-kt-app-sidebar-minimize/s', $normal);
        $this->assertDoesNotMatchRegularExpression('/id="kt_app_sidebar_toggle"[^>]*data-kt-toggle-state="active"/s', $normal);
    }
}
