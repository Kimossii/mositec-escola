<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\ComTenantDeTeste;

abstract class TestCase extends BaseTestCase
{
    use ComTenantDeTeste;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararTenantDeTeste();
    }
}
