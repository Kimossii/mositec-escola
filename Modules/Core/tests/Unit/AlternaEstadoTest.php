<?php

namespace Modules\Core\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Enums\Estado;
use Modules\Core\Traits\AlternaEstado;
use PHPUnit\Framework\TestCase;

class ModeloComEstadoFake extends Model
{
    protected $fillable = ['estado'];
    public array $atributosAtualizados = [];

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->atributosAtualizados = $attributes;
        $this->estado = $attributes['estado'];

        return true;
    }
}

class AlternaEstadoConsumidorFake
{
    use AlternaEstado;

    public function chamar(Model $model): Model
    {
        return $this->alternarEstado($model);
    }
}

class AlternaEstadoTest extends TestCase
{
    public function test_alterna_de_ativo_para_inativo(): void
    {
        $model = new ModeloComEstadoFake(['estado' => Estado::ATIVO->value]);

        $resultado = (new AlternaEstadoConsumidorFake())->chamar($model);

        $this->assertSame(Estado::INATIVO->value, $resultado->estado);
        $this->assertSame(['estado' => Estado::INATIVO->value], $model->atributosAtualizados);
    }

    public function test_alterna_de_inativo_para_ativo(): void
    {
        $model = new ModeloComEstadoFake(['estado' => Estado::INATIVO->value]);

        $resultado = (new AlternaEstadoConsumidorFake())->chamar($model);

        $this->assertSame(Estado::ATIVO->value, $resultado->estado);
    }
}
