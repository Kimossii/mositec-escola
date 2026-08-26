<?php

namespace Modules\Permissao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Usuario\Models\User;
// use Modules\Permissao\Database\Factories\UserPermissaoFactory;

class UserPermissao extends Model
{
    use HasFactory;
    protected $table = 'user_permissoes';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'users_id',
        'modulo_id',
        'acao_id',
        'permitido',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    public function modulo()
    {
        return $this->belongsTo(Modulo::class, 'modulo_id');
    }

    public function acao()
    {
        return $this->belongsTo(Acao::class, 'acao_id');
    }

    // protected static function newFactory(): UserPermissaoFactory
    // {
    //     // return UserPermissaoFactory::new();
    // }
}
