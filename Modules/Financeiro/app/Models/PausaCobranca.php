<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/**
 * Pausa de cobrança de um ano lectivo. Com `cobra_propina` = true é só informativa e não tem linhas filhas;
 * com false, os meses tocados e a isenção de cada um vivem em {@see PausaCobrancaMes} e saltam-se na geração.
 */
class PausaCobranca extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    protected $table = 'pausas_cobranca';

    protected $fillable = [
        'ano_lectivo_id',
        'descricao',
        'data_inicio',
        'data_fim',
        'cobra_propina',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $casts = [
        'ano_lectivo_id' => 'integer',
        'data_inicio' => 'date:Y-m-d',
        'data_fim' => 'date:Y-m-d',
        'cobra_propina' => 'boolean',
    ];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id');
    }

    public function meses(): HasMany
    {
        return $this->hasMany(PausaCobrancaMes::class, 'pausa_cobranca_id')->orderBy('ano')->orderBy('mes');
    }

    /**
     * Substitui os meses tocados da pausa. Com `cobra_propina` = true fica sem linhas.
     *
     * @param  list<array{ano: int, mes: int, isento: bool}>  $meses
     */
    public function substituirMeses(array $meses): void
    {
        $this->meses()->delete();

        foreach ($meses as $mes) {
            $this->meses()->create([
                'ano' => $mes['ano'],
                'mes' => $mes['mes'],
                'isento' => $mes['isento'],
            ]);
        }
    }
}
