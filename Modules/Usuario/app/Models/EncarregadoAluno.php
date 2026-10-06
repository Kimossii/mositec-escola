<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;

class EncarregadoAluno extends Model
{
    use PertenceAoTenant;

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
