<?php

use function Nekofar\Slim\Pest\get;

beforeEach(function (): void {
    $app = require __DIR__ . '/../../config/bootstrap.php';
    $this->setUpApp($app);
});

it('can see home page', function (): void {
    get('/docs')
        ->assertStatus(302)
        ->assertHeader('Location', '/docs/v1');
    get('/docs/v1')->assertOk();
});
