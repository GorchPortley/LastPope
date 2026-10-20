<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Seed the base data (roles, settings, themes, ...) Wave needs to render its pages.
     */
    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests must not depend on a built Vite manifest (`npm run build`).
        $this->withoutVite();
    }
}
