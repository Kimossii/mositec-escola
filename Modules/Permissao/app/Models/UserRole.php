<?php

namespace Modules\Permissao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Usuario\Models\User;
// use Modules\Permissao\Database\Factories\UserRoleFactory;

class UserRole extends Model
{
    use HasFactory;
    protected $table = 'user_roles';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'users_id',
        'role_id',
        'criado_por',
        'editado_por',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
    
    // protected static function newFactory(): UserRoleFactory
    // {
    //     // return UserRoleFactory::new();
    // }
}
