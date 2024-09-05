<?php

use function Nekofar\Slim\Pest\get;

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
});

it('redirects to current version', function (): void {
	get('/docs/api')
		->assertStatus(302)
		->assertHeader('Location', '/docs/api/v3');
});

it('can see api docs homepage', function (): void {
	get('/docs/api/v3')
		->assertOk()
		->assertSee('swagger-ui');
});
