<?php

namespace Modules\Usuario\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\UserPermissao;
// use Modules\Usuario\Database\Factories\UserFactory;

class User extends Authenticatable
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.teste de workflow
     */
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'dados_pessoa_id',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    // =========================
    // RELACIONAMENTOS
    // =========================

    public function pessoa()
    {
        return $this->belongsTo(DadosPessoal::class, 'dados_pessoa_id');
    }

    public function criadoPor()
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor()
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public function usuariosCriados()
    {
        return $this->hasMany(User::class, 'criado_por');
    }

    public function usuariosEditados()
    {
        return $this->hasMany(User::class, 'editado_por');
    }
    const ESTADO_INATIVO = 0;
    const ESTADO_ATIVO = 1;

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'users_id', 'role_id');
    }
    public function permissoes()
    {
        return $this->hasMany(UserPermissao::class, 'users_id');
    }
}
