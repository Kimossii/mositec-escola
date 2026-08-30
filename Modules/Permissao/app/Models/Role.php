<?php

namespace Modules\Permissao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Usuario\Models\User;
// use Modules\Permissao\Database\Factories\RoleFactory;

class Role extends Model
{
    use HasFactory;
    use SincronizaEstadoDescricao;

    protected $table = 'roles';

    public const PERFIL_PERSONALIZADO = -1;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'nome',
        'descricao',
        'estado',
        'criado_por',
        'editado_por',
    ];


    public function criadoPor()
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor()
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'users_id');
    }
    public function permissoes()
    {
        return $this->hasMany(RolePermissao::class);
    }

    public function eSistema(): bool
    {
        return $this->nome !== self::PERFIL_PERSONALIZADO;
    }

    // protected static function newFactory(): RoleFactory
    // {
    //     // return RoleFactory::new();
    // }
}
