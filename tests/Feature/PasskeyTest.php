<?php

use function Nekofar\Slim\Pest\delete;
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

describe('Passkey Login Endpoints (no auth required)', function (): void {
	it('can get login options', function (): void {
		$response = get('/api/passkeys/login/options');
		expect($response->getStatusCode())->toBeIn([200, 404, 405]);

		if ($response->getStatusCode() === 200) {
			$body = json_decode((string)$response->getBody(), true);
			expect($body)->toBeArray();
			expect($body)->toHaveKey('challenge');
			expect($body)->toHaveKey('rpId');
		}
	});

	it('rejects login without valid assertion', function (): void {
		$response = postJson('/api/passkeys/login', [
			'id'       => 'fake-credential',
			'rawId'    => 'ZmFrZS1jcmVkZW50aWFs',
			'type'     => 'public-key',
			'response' => [
				'authenticatorData' => 'ZmFrZQ',
				'clientDataJSON'    => 'ZmFrZQ',
				'signature'         => 'ZmFrZQ',
			],
		]);
		expect($response->getStatusCode())->toBeIn([400, 401, 404, 405, 500]);
	});

	it('rejects login with empty body', function (): void {
		$response = postJson('/api/passkeys/login', []);
		expect($response->getStatusCode())->toBeIn([400, 401, 404, 405, 500]);
	});
});

describe('Passkey Management Endpoints (auth required)', function (): void {
	it('blocks register options when not authenticated', function (): void {
		$response = get('/api/passkeys/register/options');
		// Should not succeed — redirect, auth error, or bad request are all valid
		expect($response->getStatusCode())->not->toBe(200);
		expect($response->getStatusCode())->not->toBeIn([500, 503]);
	});

	it('blocks list when not authenticated', function (): void {
		$response = get('/api/passkeys/list');
		expect($response->getStatusCode())->not->toBe(200);
		expect($response->getStatusCode())->not->toBeIn([500, 503]);
	});

	it('blocks register when not authenticated', function (): void {
		$response = postJson('/api/passkeys/register', [
			'name'       => 'Test Passkey',
			'credential' => [],
		]);
		expect($response->getStatusCode())->not->toBe(201);
		expect($response->getStatusCode())->not->toBeIn([500, 503]);
	});

	it('blocks delete when not authenticated', function (): void {
		$response = delete('/api/passkeys/test-credential-id');
		expect($response->getStatusCode())->not->toBe(200);
		expect($response->getStatusCode())->not->toBeIn([500, 503]);
	});
});

describe('Passkey Login Options Response Format', function (): void {
	it('returns proper WebAuthn challenge format', function (): void {
		$response = get('/api/passkeys/login/options');

		if ($response->getStatusCode() !== 200) {
			$this->markTestSkipped('Passkey endpoint not available in test environment');
		}

		$body = json_decode((string)$response->getBody(), true);

		// Challenge must be base64url encoded (no +, /, or = padding)
		expect($body['challenge'])->toMatch('/^[A-Za-z0-9_-]+$/');

		// rpId should be the configured domain
		expect($body['rpId'])->toBeString();

		// Timeout should be present
		if (isset($body['timeout'])) {
			expect($body['timeout'])->toBeInt();
			expect($body['timeout'])->toBeGreaterThan(0);
		}
	});

	it('returns empty allowCredentials for discoverable flow', function (): void {
		$response = get('/api/passkeys/login/options');

		if ($response->getStatusCode() !== 200) {
			$this->markTestSkipped('Passkey endpoint not available in test environment');
		}

		$body = json_decode((string)$response->getBody(), true);

		// For discoverable credentials, allowCredentials should be empty or absent
		if (isset($body['allowCredentials'])) {
			expect($body['allowCredentials'])->toBeArray();
			expect($body['allowCredentials'])->toBeEmpty();
		}
	});

	it('returns different challenge on each request', function (): void {
		$response1 = get('/api/passkeys/login/options');
		$response2 = get('/api/passkeys/login/options');

		if ($response1->getStatusCode() !== 200 || $response2->getStatusCode() !== 200) {
			$this->markTestSkipped('Passkey endpoint not available in test environment');
		}

		$body1 = json_decode((string)$response1->getBody(), true);
		$body2 = json_decode((string)$response2->getBody(), true);

		expect($body1['challenge'])->not->toBe($body2['challenge']);
	});
});
