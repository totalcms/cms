<?php

use function Nekofar\Slim\Pest\get;

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
});

it('redirects to current version', function (): void {
	get('/api/docs/api')
		->assertStatus(302)
		->assertHeader('Location', '/api/docs/api/v3');
});

it('can see api docs homepage', function (): void {
	get('/api/docs/api/v3')
		->assertOk()
		->assertSee('swagger-ui');
});
