<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Usuario\Database\Factories\UserPermissaoFactory;

class UserPermissao extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): UserPermissaoFactory
    // {
    //     // return UserPermissaoFactory::new();
    // }
}
