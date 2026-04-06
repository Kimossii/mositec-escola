<?php

namespace Modules\Autenticacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Autenticacao\Database\Factories\LicencaFactory;

class Licenca extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): LicencaFactory
    // {
    //     // return LicencaFactory::new();
    // }
}
