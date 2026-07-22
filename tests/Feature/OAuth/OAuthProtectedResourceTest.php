<?php

declare(strict_types=1);

use function TotalCMS\Slim\Pest\get;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('OAuth protected-resource metadata (RFC 9728)', function (): void {
	it('serves protected-resource metadata with the required fields', function (): void {
		$response = get('/.well-known/oauth-protected-resource');

		expect($response->getStatusCode())->toBe(200);

		$body = json_decode((string)$response->getBody(), true);
		expect($body)->toBeArray()
			->toHaveKeys(['resource', 'authorization_servers', 'scopes_supported', 'bearer_methods_supported']);
		expect($body['authorization_servers'])->toBeArray()->not->toBeEmpty();
		expect($body['bearer_methods_supported'])->toContain('header');
	});

	it('returns JSON content-type', function (): void {
		$response = get('/.well-known/oauth-protected-resource');

		expect($response->getHeaderLine('Content-Type'))->toContain('application/json');
	});
});
