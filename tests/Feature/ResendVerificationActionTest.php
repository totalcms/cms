<?php

use function Nekofar\Slim\Pest\get;
use function Nekofar\Slim\Pest\post;

beforeAll(function (): void {
	recursiveDelete(cmsDataDir());
});

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

describe('ResendVerificationAction', function (): void {
	it('displays resend verification form', function (): void {
		$response = get('/admin/resend-verification');
		expect($response->getStatusCode())->toBe(200);
	});

	it('displays resend verification form for specific collection', function (): void {
		$response = get('/admin/resend-verification/members');
		expect($response->getStatusCode())->toBe(200);
	});
});

describe('ResendVerificationSubmitAction', function (): void {
	it('redirects when email is empty', function (): void {
		$response = post('/admin/resend-verification', ['email' => '']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects when email is invalid', function (): void {
		$response = post('/admin/resend-verification', ['email' => 'not-an-email']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('redirects with success for valid email format', function (): void {
		// Even if user does not exist, returns success to prevent enumeration.
		$response = post('/admin/resend-verification', ['email' => 'unknown@example.com']);
		expect($response->getStatusCode())->toBe(302);
	});

	it('handles collection-specific resend verification', function (): void {
		$response = post('/admin/resend-verification/members', ['email' => 'test@example.com']);
		expect($response->getStatusCode())->toBe(302);
	});
});
