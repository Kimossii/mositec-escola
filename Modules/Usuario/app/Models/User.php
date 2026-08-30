<?php

namespace Modules\Usuario\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\UserPermissao;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Enums\TipoLogin;

class User extends Authenticatable
{
    use HasFactory;
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'numero_matricula',
        'tipo_login',
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
        'tipo_login' => TipoLogin::class,
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

    public function educandos()
    {
        return $this->belongsToMany(User::class, 'encarregados_alunos', 'encarregado_id', 'aluno_id')
            ->withPivot('parentesco')
            ->withTimestamps();
    }

    public function encarregados()
    {
        return $this->belongsToMany(User::class, 'encarregados_alunos', 'aluno_id', 'encarregado_id')
            ->withPivot('parentesco')
            ->withTimestamps();
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

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $user->estado_descricao = Estado::from($user->estado ?? 1)->label();
            $user->tipo_login_descricao = ($user->tipo_login ?? TipoLogin::EMAIL)->label();
        });
    }
}
