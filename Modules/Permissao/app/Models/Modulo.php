<?php

namespace Modules\Permissao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Core\Traits\SincronizaEstadoDescricao;
// use Modules\Permissao\Database\Factories\ModuloFactory;

class Modulo extends Model
{
    use HasFactory;
    use SincronizaEstadoDescricao;

    protected $table = 'modulos';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'nome',
        'descricao',
        'estado',
    ];

    public function permissoes()
    {
        return $this->hasMany(UserPermissao::class, 'modulo_id');
    }

    // protected static function newFactory(): ModuloFactory
    // {
    //     // return ModuloFactory::new();
    // }
}
