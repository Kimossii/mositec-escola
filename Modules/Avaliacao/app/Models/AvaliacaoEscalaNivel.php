<?php

namespace Modules\Avaliacao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;

class AvaliacaoEscalaNivel extends Model
{
    use PertenceAoTenant;

    protected $table = 'avaliacao_escala_niveis';

    protected $fillable = ['escala_id', 'codigo', 'designacao', 'ordem'];

    protected $casts = ['ordem' => 'integer'];

    public function escala(): BelongsTo
    {
        return $this->belongsTo(AvaliacaoEscala::class, 'escala_id');
    }
}
