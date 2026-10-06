<?php

namespace Modules\Plataforma\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Modules\Core\Traits\SincronizaEstadoDescricao;

/**
 * Operador da Plataforma MosiTec. Não usa PertenceAoTenant: não pertence a nenhuma escola
 * (tabela global, e-mail único em toda a instalação). A senha só se grava com hash
 * (Hash::make nas Actions, como no resto do projecto).
 */
class SuperAdmin extends Authenticatable
{
    use SincronizaEstadoDescricao;

    protected $table = 'super_admins';

    protected $fillable = [
        'name',
        'email',
        'password',
        'estado',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'estado' => 1,
        'deve_alterar_senha' => false,
    ];

    protected $casts = [
        'deve_alterar_senha' => 'boolean',
        'ultimo_login_em' => 'datetime',
    ];

    public function estaActivo(): bool
    {
        return (int) $this->estado === 1;
    }
}
