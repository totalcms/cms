<?php

use function Nekofar\Slim\Pest\get;

beforeEach(function (): void {
    $app = require __DIR__ . '/../../config/bootstrap.php';
    $this->setUpApp($app);
});

it('redirects to current version', function (): void {
    get('/docs')
        ->assertStatus(302)
        ->assertHeader('Location', '/docs/v1');
});

it('can see api docs homepage', function (): void {
    get('/docs/v1')
        ->assertOk()
        ->assertSee('swagger-ui');
});
