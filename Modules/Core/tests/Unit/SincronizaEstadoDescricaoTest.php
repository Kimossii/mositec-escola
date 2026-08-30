<?php

namespace Modules\Core\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Modules\Core\Enums\Estado;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use PHPUnit\Framework\TestCase;

class ModeloComEstadoDescricaoFake extends Model
{
    use SincronizaEstadoDescricao;

    protected $table = 'modelos_fake';
    protected $fillable = ['estado', 'estado_descricao'];
    public $timestamps = false;
}

class SincronizaEstadoDescricaoTest extends TestCase
{
    private static ?ConnectionResolverInterface $resolverOriginal = null;

    private static $dispatcherOriginal = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Model::$resolver e Model::$dispatcher são estáticos e partilhados
        // com o resto da suite (que corre no mesmo processo via
        // `php artisan test`); guardamos o estado da app real uma única vez
        // para o repor em tearDownAfterClass() e não contaminar os testes
        // que correm a seguir. O boot do Eloquent (bootSincronizaEstadoDescricao)
        // também só acontece uma vez por classe de modelo, por isso o Capsule
        // tem de ficar de pé durante toda a classe, não recriado por teste.
        self::$resolverOriginal = Model::getConnectionResolver();
        self::$dispatcherOriginal = Model::getEventDispatcher();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('modelos_fake', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('estado');
            $table->string('estado_descricao')->nullable();
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::$resolverOriginal
            ? Model::setConnectionResolver(self::$resolverOriginal)
            : Model::unsetConnectionResolver();

        self::$dispatcherOriginal
            ? Model::setEventDispatcher(self::$dispatcherOriginal)
            : Model::unsetEventDispatcher();

        parent::tearDownAfterClass();
    }

    public function test_preenche_estado_descricao_ao_gravar_ativo(): void
    {
        $modelo = ModeloComEstadoDescricaoFake::create(['estado' => Estado::ATIVO->value]);

        $this->assertSame('Ativo', $modelo->fresh()->estado_descricao);
    }

    public function test_preenche_estado_descricao_ao_gravar_inativo(): void
    {
        $modelo = ModeloComEstadoDescricaoFake::create(['estado' => Estado::INATIVO->value]);

        $this->assertSame('Inativo', $modelo->fresh()->estado_descricao);
    }
}
