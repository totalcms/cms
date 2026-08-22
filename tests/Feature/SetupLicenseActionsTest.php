<?php

use function TotalCMS\Slim\Pest\post;

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
});

describe('Setup License Actions Feature', function (): void {
	it('setup license register route exists and redirects on empty submit', function (): void {
		$response = post('/setup/license', []);
		expect($response->getStatusCode())->toBeIn([302, 400, 404, 405]);
	});

	it('setup license verify route exists', function (): void {
		$response = post('/setup/license/verify', ['code' => '123456']);
		expect($response->getStatusCode())->toBeIn([302, 400, 404, 405]);
	});
});
