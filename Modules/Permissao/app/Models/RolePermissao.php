<?php

namespace Modules\Permissao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Permissao\Database\Factories\RolePermissaoFactory;

class RolePermissao extends Model
{
    use HasFactory;
    protected $table = 'role_permissoes';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['role_id', 'modulo_id', 'acao_id'];
    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function modulo()
    {
        return $this->belongsTo(Modulo::class);
    }

    public function acao()
    {
        return $this->belongsTo(Acao::class);
    }

    // protected static function newFactory(): RolePermissaoFactory
    // {
    //     // return RolePermissaoFactory::new();
    // }
}
