<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // TenantContext holds static state. Leaking it between tests would let
        // an isolation failure show up as a passing test — the one outcome this
        // suite must never produce.
        TenantContext::forget();

        parent::tearDown();
    }
}
