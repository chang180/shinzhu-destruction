<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ApplicationFoundationTest extends TestCase
{
    public function test_landing_page_exposes_the_vue_mount_point(): void
    {
        $response = $this->get('/');

        $response->assertSee('id="app"', false);
    }

    public function test_application_foundation_uses_shared_hosting_defaults(): void
    {
        $this->assertSame('sqlite', Config::get('database.default'));
        $this->assertSame('sync', Config::get('queue.default'));
        $this->assertSame('file', Config::get('cache.stores.file.driver'));
    }
}
