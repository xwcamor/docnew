<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La home `/` requiere auth (redirige a login si no está logueado).
     * Este test deja constancia de ese contrato — si alguien lo cambia a
     * página pública sin querer, este test salta.
     */
    public function test_root_url_redirects_unauthenticated_users(): void
    {
        $response = $this->get('/');
        $response->assertRedirect();
    }
}
