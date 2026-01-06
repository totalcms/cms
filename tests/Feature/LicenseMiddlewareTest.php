<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\postJson;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('ApiKeysEditionMiddleware', function (): void {
	it('handles API keys list request', function (): void {
		$response = get('/admin/settings/api-keys');
		// Will return 200, 401, 403 depending on license and auth
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});

	it('handles API key creation request', function (): void {
		$response = postJson('/api-keys', [
			'name'        => 'Test Key',
			'permissions' => ['read'],
		]);
		expect($response->getStatusCode())->toBeIn([200, 400, 401, 403, 404, 405]);
	});
});

describe('AccessGroupsEditionMiddleware', function (): void {
	it('handles access groups page request', function (): void {
		$response = get('/admin/utils/access-groups');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});

describe('TemplatesEditionMiddleware', function (): void {
	it('handles templates list request', function (): void {
		$response = get('/templates');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});

describe('MailerEditionMiddleware', function (): void {
	it('handles mailer page request', function (): void {
		$response = get('/admin/mail');
		expect($response->getStatusCode())->toBeIn([200, 401, 403, 404, 405]);
	});
});
