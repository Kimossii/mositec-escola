<?php

namespace Modules\Permissao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Core\Traits\SincronizaEstadoDescricao;
// use Modules\Permissao\Database\Factories\AcaoFactory;

class Acao extends Model
{
    use HasFactory;
    use SincronizaEstadoDescricao;

    protected $table = 'acoes';


    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'nome',
        'numero',
        'estado',
    ];
    public function permissoes()
    {
        return $this->hasMany(UserPermissao::class, 'acao_id');
    }

    // protected static function newFactory(): AcaoFactory
    // {
    //     // return AcaoFactory::new();
    // }
}
