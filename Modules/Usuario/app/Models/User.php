<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
// use Modules\Usuario\Database\Factories\UserFactory;

class User extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    use Notifiable;

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

    // Quem criou este usuário
    public function criadoPor()
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    // Quem editou este usuário
    public function editadoPor()
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    // Usuários criados por este usuário
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

}
