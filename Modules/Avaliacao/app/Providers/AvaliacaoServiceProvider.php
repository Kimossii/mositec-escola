<?php

namespace Modules\Avaliacao\Providers;

use Modules\Avaliacao\Models\AvaliacaoRegraNivel;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Turma\Models\NivelAcademico;

class AvaliacaoServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Avaliacao';

    protected string $nameLower = 'avaliacao';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // Uma classe retirada leva consigo as ligações às regras de avaliação: são configuração, não informação da escola.
        NivelAcademico::deleted(fn (NivelAcademico $nivel) => AvaliacaoRegraNivel::query()->where('nivel_academico_id', $nivel->id)->delete());
    }
}
