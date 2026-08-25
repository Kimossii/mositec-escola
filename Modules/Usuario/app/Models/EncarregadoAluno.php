<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;

class EncarregadoAluno extends Model
{
    protected $table = 'encarregados_alunos';

    protected $fillable = ['encarregado_id', 'aluno_id', 'parentesco'];

    public function encarregado()
    {
        return $this->belongsTo(User::class, 'encarregado_id');
    }

    public function aluno()
    {
        return $this->belongsTo(User::class, 'aluno_id');
    }
}
