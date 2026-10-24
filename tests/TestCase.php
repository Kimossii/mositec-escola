<?php

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ComTenantDeTeste;

abstract class TestCase extends BaseTestCase
{
    use ComTenantDeTeste;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararTenantDeTeste();
    }

    /** Um relógio congelado num teste nunca deve passar para o seguinte (os testes da fila dependem do tempo real). */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Imagem de teste (1×1, PNG ou JPEG conforme a extensão) sem depender da extensão GD, que
     * `UploadedFile::fake()->image()` exige.
     */
    protected function imagemFalsa(string $nome): File
    {
        $jpeg = in_array(strtolower(pathinfo($nome, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true);
        $bytes = $jpeg
            ? '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='
            : 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        return UploadedFile::fake()->createWithContent($nome, base64_decode($bytes));
    }
}
